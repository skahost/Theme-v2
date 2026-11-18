<?php

namespace DriftRegister\Http\Controllers;

use DriftRegister\Models\Signup;
use DriftRegister\Models\SsoIdentity;
use DriftRegister\Security\TurnstileVerifier;
use DriftRegister\Settings\RegistrationSettings;
use DriftRegister\Sso\SsoConnections;
use DriftRegister\Sso\SsoException;
use DriftRegister\Sso\SsoProvider;
use DriftRegister\Sso\SsoService;
use DriftRegister\Support\AccountCreator;
use DriftRegister\Support\AccountDefaults;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Pterodactyl\Contracts\Users\CreatesUsers;
use Pterodactyl\Models\User;
use Throwable;

/**
 * Google and Discord sign-in, registration and account linking.
 *
 * Sign-in matches only on the linked (provider, provider_user_id) pair. An account is
 * never matched or linked by email address, so nobody can take over an account by
 * controlling a provider account that carries the same address.
 */
class SsoController extends Controller
{
    private const TURNSTILE_SESSION_KEY = 'drift-register.turnstile-registration';

    private const TURNSTILE_REGISTRATION_TTL_SECONDS = 600;

    public function __construct(
        private readonly RegistrationSettings $settings,
        private readonly SsoService $sso,
        private readonly SsoConnections $connections,
        private readonly TurnstileVerifier $turnstile,
    ) {}

    /** Public. Starts a login or register attempt: /extensions/drift-register/sso/{provider}/redirect?mode= */
    public function redirect(Request $request, string $provider): RedirectResponse
    {
        $definition = $this->available($provider);
        $requested = $request->query('mode');
        // "connect" only proves ownership and returns to the sign-up form.
        $mode = in_array($requested, ['register', 'connect'], true) ? $requested : 'login';

        if ($mode !== 'login' && ! $this->settings->enabled()) {
            return $this->back('register', 'registration_closed', $provider);
        }
        if ($mode !== 'login' && $this->settings->mode($provider) === 'hidden') {
            return $this->back('register', 'provider_unavailable', $provider);
        }
        if ($mode === 'register' && $this->settings->turnstileEnabled()) {
            return $this->back('register', 'turnstile_required', $provider);
        }

        return redirect()->away($this->sso->begin($request, $definition, $mode));
    }

    /** Verifies Turnstile before starting an SSO flow that may create an account. */
    public function startRegistration(Request $request, string $provider): JsonResponse
    {
        abort_unless($this->settings->enabled(), 404);
        abort_if($this->settings->mode($provider) === 'hidden', 404);

        $definition = $this->available($provider);
        $this->turnstile->verify($request, $this->settings);
        $redirectUrl = $this->sso->begin($request, $definition, 'register');

        if ($this->settings->turnstileEnabled()) {
            $request->session()->put(self::TURNSTILE_SESSION_KEY, [
                'provider' => $provider,
                'verified_at' => now()->timestamp,
            ]);
        }

        return new JsonResponse(['redirect_url' => $redirectUrl]);
    }

    /** Signed-in users only (routes/authenticated.php). Starts linking from the account page. */
    public function link(Request $request, string $provider): RedirectResponse
    {
        $user = $request->user();
        abort_unless($user instanceof User, 403);

        if (! $this->settings->ssoReady($provider)) {
            return $this->back('link', 'provider_unavailable', $provider);
        }
        $definition = $this->available($provider);

        if (SsoIdentity::query()->where('user_id', $user->id)->where('provider', $provider)->exists()) {
            return $this->back('link', 'already_linked', $provider);
        }

        return redirect()->away($this->sso->begin($request, $definition, 'link', $user->id));
    }

    /** Public. The provider sends the browser back here: /extensions/drift-register/sso/{provider}/callback */
    public function callback(Request $request, string $provider, CreatesUsers $creator): RedirectResponse
    {
        $definition = $this->available($provider);

        // One attempt only: the session entry is removed whatever the outcome.
        $stored = $request->session()->pull(SsoService::SESSION_KEY);
        $stored = is_array($stored) ? $stored : null;
        $mode = in_array($stored['mode'] ?? null, ['login', 'register', 'link', 'connect'], true) ? $stored['mode'] : 'login';
        if ($mode === 'register' && $this->settings->turnstileEnabled()) {
            $challenge = $request->session()->pull(self::TURNSTILE_SESSION_KEY);
            $verifiedAt = is_array($challenge) ? ($challenge['verified_at'] ?? null) : null;
            if (($challenge['provider'] ?? null) !== $provider
                || ! is_int($verifiedAt)
                || $verifiedAt < now()->subSeconds(self::TURNSTILE_REGISTRATION_TTL_SECONDS)->timestamp) {
                return $this->back('register', 'turnstile_required', $provider);
            }
        }

        try {
            $profile = $this->sso->profileFromCallback($request, $definition, $stored);

            if ($mode === 'link') {
                return $this->finishLink($request, $provider, $profile, $stored);
            }

            $identity = SsoIdentity::query()
                ->where('provider', $provider)
                ->where('provider_user_id', $profile['id'])
                ->first();

            if ($mode === 'connect') {
                return $this->connect($request, $provider, $profile, $identity !== null && $identity->user !== null);
            }

            if ($identity !== null && $identity->user !== null) {
                $this->refreshIdentity($identity, $provider, $profile, true);

                return $this->signIn($request, $identity->user, $provider);
            }

            // Not linked to any account.
            if ($mode === 'login') {
                return $this->back('login', 'not_registered', $provider);
            }

            return $this->register($request, $creator, $provider, $profile);
        } catch (SsoException $exception) {
            return $this->back($mode, $exception->reason, $provider);
        } catch (Throwable $exception) {
            report($exception);

            return $this->back($mode, 'failed', $provider);
        }
    }

    /**
     * The visitor proved they own this provider account while filling in the sign-up form.
     * Remember it for the form and send them back to it.
     *
     * @param  array{id: string, email: string, email_verified: bool, username: string, first: string, last: string, name?: string, avatar?: string}  $profile
     */
    private function connect(Request $request, string $provider, array $profile, bool $alreadyRegistered): RedirectResponse
    {
        if (! $this->settings->enabled()) {
            throw new SsoException('registration_closed');
        }
        if ($this->settings->mode($provider) === 'hidden') {
            throw new SsoException('provider_unavailable');
        }
        if ($alreadyRegistered) {
            throw new SsoException('already_registered');
        }

        $this->connections->remember($request, $provider, $profile);

        return redirect('/register?' . http_build_query(['sso_connected' => $provider]));
    }

    /**
     * Discord usernames and Google / Discord names and pictures change, so a sign-in or
     * re-link keeps the saved ones current. A sign-in also stamps the last sign-in time.
     *
     * @param  array<string, mixed>  $profile
     */
    private function refreshIdentity(SsoIdentity $identity, string $provider, array $profile, bool $signedIn = false): void
    {
        $changes = SsoConnections::details($profile);
        $changes['email'] = SsoConnections::email($profile['email'] ?? null);
        $username = SsoConnections::username($provider, $profile['username'] ?? null);
        if ($username !== null) {
            $changes['username'] = $username;
        }
        // Keep what is saved when the provider sent nothing this time.
        $changes = array_filter($changes, fn ($value) => $value !== null);
        if ($signedIn) {
            $changes['last_login_at'] = now();
        }
        if ($changes !== []) {
            $identity->forceFill($changes)->save();
        }
    }

    /**
     * @param  array{id: string, email: string, email_verified: bool, username: string, first: string, last: string, name?: string, avatar?: string}  $profile
     * @param  array<string, mixed>|null  $stored
     */
    private function finishLink(Request $request, string $provider, array $profile, ?array $stored): RedirectResponse
    {
        $user = Auth::user();
        if (! $user instanceof User || (int) ($stored['user_id'] ?? 0) !== (int) $user->id) {
            throw new SsoException('session_mismatch');
        }

        $existing = SsoIdentity::query()
            ->where('provider', $provider)
            ->where('provider_user_id', $profile['id'])
            ->first();

        if ($existing !== null && (int) $existing->user_id !== (int) $user->id) {
            throw new SsoException('identity_taken');
        }

        if ($existing === null) {
            if (SsoIdentity::query()->where('user_id', $user->id)->where('provider', $provider)->exists()) {
                throw new SsoException('already_linked');
            }

            SsoIdentity::query()->create([
                'user_id' => $user->id,
                'provider' => $provider,
                'provider_user_id' => $profile['id'],
                'email' => SsoConnections::email($profile['email'] ?? null),
                'username' => SsoConnections::username($provider, $profile['username'] ?? null),
                ...SsoConnections::details($profile),
                'last_login_at' => now(),
            ]);
        } elseif ((int) $existing->user_id === (int) $user->id) {
            $this->refreshIdentity($existing, $provider, $profile);
        }

        return redirect('/account?' . http_build_query(['sso' => 'linked', 'sso_provider' => $provider]));
    }

    /**
     * Create an account from a verified provider profile, link it, and sign it in.
     *
     * @param  array{id: string, email: string, email_verified: bool, username: string, first: string, last: string, name?: string, avatar?: string}  $profile
     */
    private function register(Request $request, CreatesUsers $creator, string $provider, array $profile): RedirectResponse
    {
        if (! $this->settings->enabled()) {
            throw new SsoException('registration_closed');
        }
        if ($this->settings->mode($provider) === 'hidden') {
            throw new SsoException('provider_unavailable');
        }
        if ($profile['email'] === '') {
            throw new SsoException('no_email');
        }
        if (! $profile['email_verified']) {
            throw new SsoException('email_unverified');
        }
        if (User::query()->where('email', $profile['email'])->exists()) {
            // Do not link by email: the owner signs in with their password and links from their account page.
            throw new SsoException('email_taken');
        }

        // Remember this provider account, then check whether the form still has to be filled in
        // (a required company, phone or last name, or another required provider).
        $this->connections->remember($request, $provider, $profile);
        $connected = $this->connections->all($request);
        if ($this->settings->signupNeedsForm(array_keys($connected), $profile)) {
            return redirect('/register?' . http_build_query(['sso_connected' => $provider]));
        }

        $data = [
            'username' => AccountDefaults::uniqueUsername($profile['username'] !== '' ? $profile['username'] : AccountDefaults::seedFromEmail($profile['email'])),
            'email' => $profile['email'],
            'name_first' => AccountDefaults::name($profile['first']),
            'name_last' => AccountDefaults::name($profile['last']),
        ];

        // If only the Panel's "set your password" email fails (mail not configured), the new
        // account still exists and the sign-up carries on.
        $user = AccountCreator::create($creator, $data);

        DB::transaction(function () use ($user, $connected): void {
            $this->connections->link($user, SsoConnections::identities($connected));
        });
        $this->connections->forget($request);
        Signup::record($user, $provider, $request);

        return $this->signIn($request, $user, $provider);
    }

    private function signIn(Request $request, User $user, string $provider): RedirectResponse
    {
        // A provider only proves the first factor. The Panel's two-factor checkpoint is a
        // step of its own login screen and cannot be resumed after a redirect, so accounts
        // with two-factor authentication must use their password instead.
        if ($user->use_totp) {
            return $this->back('login', 'totp_required', $provider);
        }

        $request->session()->regenerate();
        Auth::guard()->login($user, true);

        return redirect('/');
    }

    private function available(string $provider): SsoProvider
    {
        $definition = SsoProvider::find($provider);
        abort_unless($definition !== null && $this->settings->ssoReady($provider), 404);

        return $definition;
    }

    /** Send the browser to the page the attempt started from, with a reason it can explain. */
    private function back(string $mode, string $reason, string $provider): RedirectResponse
    {
        $path = match ($mode) {
            'register', 'connect' => '/register',
            'link' => '/account',
            default => '/auth/login',
        };

        return redirect($path . '?' . http_build_query(['sso_error' => $reason, 'sso_provider' => $provider]));
    }
}
