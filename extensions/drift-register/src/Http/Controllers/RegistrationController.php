<?php

namespace DriftRegister\Http\Controllers;

use DriftRegister\Models\PendingRegistration;
use DriftRegister\Models\Profile;
use DriftRegister\Models\Signup;
use DriftRegister\Models\SsoIdentity;
use DriftRegister\Notifications\VerifyRegistration;
use DriftRegister\Security\TurnstileVerifier;
use DriftRegister\Settings\RegistrationSettings;
use DriftRegister\Sso\SsoConnections;
use DriftRegister\Sso\SsoProvider;
use DriftRegister\Support\AccountCreator;
use DriftRegister\Support\AccountDefaults;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Unique;
use Illuminate\Validation\ValidationException;
use Pterodactyl\Contracts\Users\CreatesUsers;
use Pterodactyl\Models\User;
use Pterodactyl\Validation\UserRules;

class RegistrationController extends Controller
{
    private const TABLE = 'ext_drift_register_pending_registrations';

    private const DISCORD_URL = 'https://discord.gg/eFQZayYSGN';

    public function __construct(private readonly SsoConnections $connections) {}

    /** Public. The standalone registration page at /register. */
    public function show(Request $request, RegistrationSettings $settings): Response
    {
        $connected = $this->connections->all($request);
        $last = $connected === [] ? null : end($connected);

        return response()
            ->view('ext-drift-register::register', [
                'enabled' => $settings->enabled(),
                'fields' => $settings->modes(),
                'endpoint' => route('extensions.drift-register.web.register'),
                'turnstileEnabled' => $settings->turnstileEnabled(),
                'turnstileConfigured' => $settings->turnstileConfigured(),
                'turnstileSiteKey' => $settings->turnstileSiteKey(),
                'discordUrl' => self::DISCORD_URL,
                'showDiscord' => ! $settings->hideDiscordButton(),
                'logo' => $this->logo(),
                'sso' => $this->ssoButtons($settings, $connected),
                'prefill' => [
                    'email' => $last['email'] ?? '',
                    'username' => $last['username'] ?? '',
                    'first' => $last['first'] ?? '',
                    'last' => $last['last'] ?? '',
                ],
                'ssoError' => $this->ssoError($request),
            ], $settings->enabled() ? 200 : 403)
            ->header('Cache-Control', 'no-store');
    }

    /** Public. Tells the login page whether to show the form, and which providers. */
    public function status(RegistrationSettings $settings): JsonResponse
    {
        $modes = $settings->modes();

        return (new JsonResponse([
            'enabled' => $settings->enabled(),
            'hide_discord' => $settings->hideDiscordButton(),
            'demo' => $settings->demoCredentials(),
            'fields' => $modes,
            'sso' => [
                'google' => $settings->ssoReady('google'),
                'discord' => $settings->ssoReady('discord'),
            ],
        ]))->header('Cache-Control', 'no-store');
    }

    public function register(
        Request $request,
        RegistrationSettings $settings,
        CreatesUsers $creator,
        TurnstileVerifier $turnstile
    ): JsonResponse {
        abort_unless($settings->enabled(), 404);
        $turnstile->verify($request, $settings);

        $modes = $settings->modes();
        $rules = UserRules::rules();
        $email = mb_strtolower(trim((string) $request->input('email')));
        $request->merge(['email' => $email]);

        // A password given at sign-up creates the account at once; without one the visitor
        // verifies their email first and sets a password from the emailed link.
        $password = (string) $request->input('password', '');
        $usePassword = $modes['password'] === 'required' || ($modes['password'] === 'optional' && $password !== '');

        $connected = $this->connections->all($request);
        $this->checkConnections($modes, $connected);

        $emailRules = $rules['email'];
        if (! $usePassword) {
            // Do not reveal which addresses already have an account: a taken address
            // gets the same response as a new one, and no email is sent.
            $emailRules = array_values(array_filter(
                $emailRules,
                fn ($rule) => ! $rule instanceof Unique && ! (is_string($rule) && str_starts_with($rule, 'unique:')),
            ));
        }
        $validation = ['email' => $emailRules];

        if ($modes['username'] !== 'hidden') {
            $usernameRules = $rules['username'];
            if (! $usePassword) {
                // Another visitor's unexpired, unverified sign-up already holds the name.
                $usernameRules[] = Rule::unique(self::TABLE, 'username')
                    ->where(fn ($query) => $query->where('expires_at', '>', now())->where('email', '!=', $email));
            }
            $validation['username'] = $this->byMode($modes['username'], $usernameRules);
        }
        if ($modes['first_name'] !== 'hidden') {
            $validation['name_first'] = $this->byMode($modes['first_name'], $rules['name_first']);
        }
        if ($modes['last_name'] !== 'hidden') {
            $validation['name_last'] = $this->byMode($modes['last_name'], $rules['name_last']);
        }
        if ($modes['company'] !== 'hidden') {
            $validation['company'] = $this->byMode($modes['company'], ['string', 'max:191']);
        }
        if ($modes['phone'] !== 'hidden') {
            $validation['phone'] = $this->byMode($modes['phone'], ['string', 'max:32', 'regex:/^[0-9+().\-\s]{5,32}$/']);
        }
        if ($modes['password'] !== 'hidden') {
            $passwordRules = $this->byMode($modes['password'], UserRules::PASSWORD);
            $confirm = $modes['password_confirm'];
            if ($usePassword && ($confirm === 'required' || ($confirm === 'optional' && filled($request->input('password_confirmation'))))) {
                $passwordRules[] = 'confirmed';
            }
            $validation['password'] = $passwordRules;
        }

        $data = $request->validate($validation);

        $username = trim((string) ($data['username'] ?? ''));
        $account = [
            'username' => $username !== '' ? $username : AccountDefaults::uniqueUsername(AccountDefaults::seedFromEmail($data['email']), ! $usePassword),
            'email' => $data['email'],
            'name_first' => AccountDefaults::name((string) ($data['name_first'] ?? '')),
            'name_last' => AccountDefaults::name((string) ($data['name_last'] ?? '')),
        ];
        $company = trim((string) ($data['company'] ?? ''));
        $phone = trim((string) ($data['phone'] ?? ''));
        $extras = ['company' => $company !== '' ? $company : null, 'phone' => $phone !== '' ? $phone : null];
        $identities = SsoConnections::identities($connected);

        if ($usePassword) {
            $user = $this->createUser($creator, [...$account, 'password' => $data['password']]);
            $this->finishUser($user, $extras, $identities);
            Signup::record($user, 'password', $request);
            $this->connections->forget($request);

            return new JsonResponse(['status' => 'created'], 201);
        }

        PendingRegistration::query()->where('expires_at', '<', now())->delete();

        if (! User::query()->where('email', $account['email'])->exists()) {
            $minutes = $settings->verificationMinutes();
            $token = Str::random(64);

            PendingRegistration::query()->updateOrCreate(['email' => $account['email']], [
                'username' => $account['username'],
                'name_first' => $account['name_first'],
                'name_last' => $account['name_last'],
                ...$extras,
                'sso' => $identities !== [] ? $identities : null,
                'token_hash' => hash('sha256', $token),
                'expires_at' => now()->addMinutes($minutes),
            ]);

            Notification::route('mail', $account['email'])->notify(new VerifyRegistration(
                $account['name_first'],
                route('extensions.drift-register.web.verify', ['token' => $token]),
                $minutes,
            ));
        }
        $this->connections->forget($request);

        return new JsonResponse(['status' => 'verification_sent'], 202);
    }

    /** Public, opened from the email. Creates the user, then the Panel emails the set-password link. */
    public function verify(Request $request, string $token, RegistrationSettings $settings, CreatesUsers $creator): Response
    {
        if (! $settings->enabled()) {
            return $this->page('closed', 403);
        }

        $pending = PendingRegistration::query()->where('token_hash', hash('sha256', $token))->first();
        if ($pending === null || $pending->expires_at->isPast()) {
            return $this->page('invalid', 410);
        }

        $taken = User::query()
            ->where('email', $pending->email)
            ->orWhere('username', $pending->username)
            ->exists();
        if ($taken) {
            $pending->delete();

            return $this->page('invalid', 410);
        }

        // No password: the Panel sets a random one, creates a reset token and emails
        // the "Setup Your Account" link, which is the normal reset-password page.
        $user = $this->createUser($creator, [
            'username' => $pending->username,
            'email' => $pending->email,
            'name_first' => $pending->name_first,
            'name_last' => $pending->name_last,
        ]);
        $this->finishUser(
            $user,
            ['company' => $pending->company, 'phone' => $pending->phone],
            is_array($pending->sso) ? $pending->sso : [],
        );
        Signup::record($user, 'email', $request);
        $pending->delete();

        return $this->page('verified');
    }

    /**
     * Every required provider must be connected, and no connected provider account may
     * already belong to a user.
     *
     * @param  array<string, string>  $modes
     * @param  array<string, array<string, mixed>>  $connected
     */
    private function checkConnections(array $modes, array $connected): void
    {
        $errors = [];

        foreach (SsoProvider::all() as $provider) {
            if ($modes[$provider->key] === 'required' && ! isset($connected[$provider->key])) {
                $errors["sso_{$provider->key}"] = "Connect your {$provider->label} account to continue.";
            }
        }
        foreach ($connected as $key => $profile) {
            $taken = SsoIdentity::query()->where('provider', $key)->where('provider_user_id', $profile['id'])->exists();
            if ($taken) {
                $label = SsoProvider::find((string) $key)?->label ?? 'That';
                $errors["sso_{$key}"] = "That {$label} account already has an account here. Sign in with it instead.";
            }
        }

        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }
    }

    /**
     * Rules for a field in a given mode: "required" keeps the rules as they are, "optional"
     * lets the field stay empty.
     *
     * @param  array<int, mixed>  $rules
     * @return array<int, mixed>
     */
    private function byMode(string $mode, array $rules): array
    {
        if ($mode === 'optional') {
            return ['nullable', ...array_values(array_filter($rules, fn ($rule) => $rule !== 'required'))];
        }

        return in_array('required', $rules, true) ? array_values($rules) : ['required', ...$rules];
    }

    /**
     * @param  array{company: string|null, phone: string|null}  $extras
     * @param  list<array{provider: string, id: string, email: string|null, username?: string|null, name?: string|null, avatar?: string|null}>  $identities
     */
    private function finishUser(User $user, array $extras, array $identities): void
    {
        if ($extras['company'] !== null || $extras['phone'] !== null) {
            Profile::query()->updateOrCreate(['user_id' => $user->id], $extras);
        }

        $this->connections->link($user, $identities);
    }

    /** @param array<string, string> $data */
    private function createUser(CreatesUsers $creator, array $data): User
    {
        return AccountCreator::create($creator, $data);
    }

    /**
     * @param  array<string, array<string, mixed>>  $connected
     * @return list<array{key: string, label: string, mode: string, connected: bool, connected_email: string, url: string, turnstile_url: string, connect_url: string}>
     */
    private function ssoButtons(RegistrationSettings $settings, array $connected): array
    {
        $buttons = [];
        foreach (SsoProvider::all() as $provider) {
            $mode = $settings->mode($provider->key);
            if ($mode === 'hidden') {
                continue;
            }

            $buttons[] = [
                'key' => $provider->key,
                'label' => $provider->label,
                'mode' => $mode,
                'connected' => isset($connected[$provider->key]),
                'connected_email' => (string) ($connected[$provider->key]['email'] ?? ''),
                // One click: signs up with this provider (continues on the form if more is needed).
                'url' => route('extensions.drift-register.web.sso.redirect', ['provider' => $provider->key, 'mode' => 'register']),
                'turnstile_url' => route('extensions.drift-register.web.sso.register', ['provider' => $provider->key]),
                // Only proves ownership and returns to the form.
                'connect_url' => route('extensions.drift-register.web.sso.redirect', ['provider' => $provider->key, 'mode' => 'connect']),
            ];
        }

        return $buttons;
    }

    /** The message for a failed SSO attempt that sent the visitor back to this page. */
    private function ssoError(Request $request): ?string
    {
        $reason = $request->query('sso_error');
        if (! is_string($reason)) {
            return null;
        }

        $label = SsoProvider::find((string) $request->query('sso_provider'))?->label ?? 'your provider';

        return match ($reason) {
            'email_taken' => "An account with this email already exists. Sign in with your password, then link {$label} from your account page.",
            'email_unverified', 'no_email' => "{$label} did not give us a verified email address, so we cannot create an account from it.",
            'registration_closed' => 'New sign-ups are currently disabled.',
            'turnstile_required' => 'Complete the Cloudflare security check and try signing up again.',
            'provider_unavailable' => "Signing up with {$label} is not available right now.",
            'already_registered' => "That {$label} account already has an account here. Sign in with it instead.",
            'denied' => 'Sign-in was cancelled.',
            'invalid_state' => 'That sign-in attempt expired. Please try again.',
            default => 'Something went wrong while signing in. Please try again.',
        };
    }

    /** The extension icon as a data URI, so the standalone page needs no public asset. */
    private function logo(): ?string
    {
        $path = dirname(__DIR__, 3) . '/resources/icon.png';

        return is_file($path) ? 'data:image/png;base64,' . base64_encode((string) file_get_contents($path)) : null;
    }

    private function page(string $state, int $status = 200): Response
    {
        return response()->view('ext-drift-register::verified', ['state' => $state], $status);
    }
}
