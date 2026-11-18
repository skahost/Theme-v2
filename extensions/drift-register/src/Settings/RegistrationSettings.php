<?php

namespace DriftRegister\Settings;

use DriftRegister\Sso\SsoProvider;
use Pterodactyl\Services\Extensions\ExtensionSettings;

/**
 * Thin wrapper over the extension's key-value store. The keys match the settings
 * definition registered in the provider.
 */
final class RegistrationSettings
{
    public function __construct(private readonly ExtensionSettings $store) {}

    public function enabled(): bool
    {
        return (bool) $this->store->get('registration_enabled', false);
    }

    public function verificationMinutes(): int
    {
        $minutes = (int) $this->store->get('verification_ttl_minutes', 60);

        return min(1440, max(5, $minutes));
    }

    /** Whether the "Join our Discord" button is hidden on the login and register pages. */
    public function hideDiscordButton(): bool
    {
        return (bool) $this->store->get('hide_discord_button', false);
    }

    public function turnstileEnabled(): bool
    {
        return (bool) $this->store->get('turnstile_enabled', false);
    }

    public function turnstileSiteKey(): string
    {
        return trim((string) $this->store->get('turnstile_site_key', ''));
    }

    /** The decrypted secret key. Never send this value to the browser. */
    public function turnstileSecretKey(): string
    {
        return (string) $this->store->get('turnstile_secret_key', '');
    }

    public function turnstileConfigured(): bool
    {
        return $this->turnstileSiteKey() !== '' && $this->turnstileSecretKey() !== '';
    }

    // ---- Demo mode -----------------------------------------------------------------

    public function demoMode(): bool
    {
        return (bool) $this->store->get('demo_mode', false);
    }

    public function demoAutofill(): bool
    {
        return (bool) $this->store->get('demo_autofill', false);
    }

    public function demoEmail(): string
    {
        return trim((string) $this->store->get('demo_email', ''));
    }

    /** Shown to every visitor of the login page by design, so it is not stored as a secret. */
    public function demoPassword(): string
    {
        return (string) $this->store->get('demo_password', '');
    }

    /**
     * The login the public status endpoint hands to the login page, or null. It only exists
     * while demo mode and auto-fill are both on and both values are filled in.
     *
     * @return array{email: string, password: string}|null
     */
    public function demoCredentials(): ?array
    {
        if (! $this->demoMode() || ! $this->demoAutofill()) {
            return null;
        }
        $email = $this->demoEmail();
        $password = $this->demoPassword();

        return $email !== '' && $password !== '' ? ['email' => $email, 'password' => $password] : null;
    }

    /** Sign-up attempts allowed per IP address per minute. */
    public function rateLimit(): int
    {
        $limit = (int) $this->store->get('rate_limit_per_minute', 5);

        return min(100, max(1, $limit));
    }

    /** @return array{registration_enabled: bool, hide_discord_button: bool, demo_mode: bool, demo_autofill: bool, demo_email: string, demo_password: string, verification_ttl_minutes: int, rate_limit_per_minute: int, fields: array<string, string>} */
    public function all(): array
    {
        return [
            'registration_enabled' => $this->enabled(),
            'hide_discord_button' => $this->hideDiscordButton(),
            'demo_mode' => $this->demoMode(),
            'demo_autofill' => $this->demoAutofill(),
            'demo_email' => $this->demoEmail(),
            'demo_password' => $this->demoPassword(),
            'verification_ttl_minutes' => $this->verificationMinutes(),
            'rate_limit_per_minute' => $this->rateLimit(),
            'fields' => $this->savedModes(),
        ];
    }

    /** @param array<string, bool|int> $values */
    public function update(array $values): void
    {
        $this->store->setMany($values);
    }

    // ---- Sign-up form fields -------------------------------------------------------

    public const MODES = ['required', 'optional', 'hidden'];

    /** Every field an admin can configure, with its default. The email address is always required. */
    public const FIELDS = [
        'username' => 'required',
        'first_name' => 'required',
        'last_name' => 'required',
        'company' => 'hidden',
        'phone' => 'hidden',
        'password' => 'required',
        'password_confirm' => 'required',
        'discord' => 'optional',
        'google' => 'optional',
    ];

    /** The mode an admin saved, ignoring whether it can take effect. */
    public function savedMode(string $field): string
    {
        $value = $this->store->get("field_{$field}");
        if (is_string($value) && in_array($value, self::MODES, true)) {
            return $value;
        }

        if ($field === 'password') {
            // Settings from before per-field modes: "Require a password at sign-up".
            $legacy = $this->store->get('require_password');
            if ($legacy !== null) {
                return (bool) $legacy ? 'required' : 'hidden';
            }
        }

        return self::FIELDS[$field] ?? 'hidden';
    }

    /**
     * The mode that applies to visitors. A sign-in provider that is not fully configured is
     * hidden, and there is nothing to confirm when the password field is hidden.
     */
    public function mode(string $field): string
    {
        $mode = $this->savedMode($field);

        if (in_array($field, SsoProvider::KEYS, true)) {
            return $this->ssoReady($field) ? $mode : 'hidden';
        }
        if ($field === 'password_confirm' && $this->savedMode('password') === 'hidden') {
            return 'hidden';
        }

        return $mode;
    }

    /** @return array<string, string> Effective mode of every field. */
    public function modes(): array
    {
        $modes = [];
        foreach (array_keys(self::FIELDS) as $field) {
            $modes[$field] = $this->mode($field);
        }

        return $modes;
    }

    /** @return array<string, string> What the admin saved. */
    public function savedModes(): array
    {
        $modes = [];
        foreach (array_keys(self::FIELDS) as $field) {
            $modes[$field] = $this->savedMode($field);
        }

        return $modes;
    }

    /** @param array<string, string> $modes */
    public function updateModes(array $modes): void
    {
        $values = [];
        foreach ($modes as $field => $mode) {
            if (array_key_exists($field, self::FIELDS) && in_array($mode, self::MODES, true)) {
                $values["field_{$field}"] = $mode;
            }
        }
        if ($values !== []) {
            $this->store->setMany($values);
        }
    }

    /**
     * Whether a sign-up that started with a provider (one click) has to continue on the form,
     * because something required cannot come from the provider.
     *
     * @param  list<string>  $connected  Providers already connected in this session.
     * @param  array{first: string, last: string}  $profile
     */
    public function signupNeedsForm(array $connected, array $profile): bool
    {
        $modes = $this->modes();

        foreach (['company', 'phone'] as $field) {
            if ($modes[$field] === 'required') {
                return true;
            }
        }
        if (($modes['first_name'] === 'required' && $profile['first'] === '')
            || ($modes['last_name'] === 'required' && $profile['last'] === '')) {
            return true;
        }
        foreach (SsoProvider::KEYS as $key) {
            if ($modes[$key] === 'required' && ! in_array($key, $connected, true)) {
                return true;
            }
        }

        return false;
    }

    // ---- Single sign-on ------------------------------------------------------------

    public function ssoEnabled(string $provider): bool
    {
        return (bool) $this->store->get("sso_{$provider}_enabled", false);
    }

    public function ssoClientId(string $provider): string
    {
        return trim((string) $this->store->get("sso_{$provider}_client_id", ''));
    }

    /** The decrypted client secret. Never send this to the browser. */
    public function ssoClientSecret(string $provider): string
    {
        return (string) $this->store->get("sso_{$provider}_client_secret", '');
    }

    public function ssoHasSecret(string $provider): bool
    {
        return $this->ssoClientSecret($provider) !== '';
    }

    /** A provider is usable only when it is switched on and has both credentials. */
    public function ssoReady(string $provider): bool
    {
        return in_array($provider, SsoProvider::KEYS, true)
            && $this->ssoEnabled($provider)
            && $this->ssoClientId($provider) !== ''
            && $this->ssoHasSecret($provider);
    }

    /** @return list<string> */
    public function ssoReadyProviders(): array
    {
        return array_values(array_filter(SsoProvider::KEYS, fn (string $key) => $this->ssoReady($key)));
    }

    /**
     * Admin view of one provider. The secret is never returned, only whether one is saved.
     *
     * @return array{enabled: bool, client_id: string, has_secret: bool, ready: bool, redirect_uri: string}
     */
    public function ssoAdmin(string $provider): array
    {
        return [
            'enabled' => $this->ssoEnabled($provider),
            'client_id' => $this->ssoClientId($provider),
            'has_secret' => $this->ssoHasSecret($provider),
            'ready' => $this->ssoReady($provider),
            'redirect_uri' => route('extensions.drift-register.web.sso.callback', ['provider' => $provider]),
        ];
    }

    /**
     * Save one provider's settings. A blank secret keeps the saved one.
     *
     * @param  array{enabled?: bool, client_id?: string|null, client_secret?: string|null}  $values
     */
    public function updateSso(string $provider, array $values): void
    {
        $plain = [];
        if (array_key_exists('enabled', $values)) {
            $plain["sso_{$provider}_enabled"] = (bool) $values['enabled'];
        }
        if (array_key_exists('client_id', $values)) {
            $plain["sso_{$provider}_client_id"] = trim((string) $values['client_id']);
        }
        if ($plain !== []) {
            $this->store->setMany($plain);
        }

        $secret = trim((string) ($values['client_secret'] ?? ''));
        if ($secret !== '') {
            $this->store->setSecret("sso_{$provider}_client_secret", $secret);
        }
    }
}
