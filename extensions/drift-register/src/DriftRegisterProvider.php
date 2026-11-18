<?php

namespace DriftRegister;

use DriftRegister\Settings\RegistrationSettings;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Pterodactyl\Extensions\ExtensionProvider;
use Pterodactyl\Services\Extensions\ExtensionSettingDefinition;
use Pterodactyl\Services\Extensions\ExtensionSettingsDefinition;

class DriftRegisterProvider extends ExtensionProvider
{
    public function register(): void
    {
        // Container bindings go here.
    }

    public function boot(): void
    {
        // Controllers read and write the same store through this wrapper, so the
        // /panel/drift-register page and Admin -> Extensions always agree.
        $this->app->instance(RegistrationSettings::class, new RegistrationSettings($this->settings()));

        $definitions = [];
        $base = [
            ExtensionSettingDefinition::make('registration_enabled', 'registration_enabled', false, ['boolean'])
                ->label('Enable registration')
                ->help('Show the "Create an account" form on the login page.')
                ->field('toggle'),
            ExtensionSettingDefinition::make('hide_discord_button', 'hide_discord_button', false, ['boolean'])
                ->label('Hide the Join our Discord button')
                ->help('Hides the button on the login and register pages. The admin pages keep it.')
                ->field('toggle'),
            ExtensionSettingDefinition::make('demo_mode', 'demo_mode', false, ['boolean'])
                ->label('Demo mode')
                ->help('Turns on the demo options below.')
                ->field('toggle'),
            ExtensionSettingDefinition::make('demo_autofill', 'demo_autofill', false, ['boolean'])
                ->label('Auto-fill the demo login')
                ->help('Demo mode only. Fills the login form with the demo email and password. Both are shown to every visitor of the login page, so use a low-privilege account, never an administrator.')
                ->field('toggle'),
            ExtensionSettingDefinition::make('demo_email', 'demo_email', '', ['nullable', 'string', 'max:191'])
                ->label('Demo email')
                ->help('The username or email filled into the login form.')
                ->field('text'),
            ExtensionSettingDefinition::make('demo_password', 'demo_password', '', ['nullable', 'string', 'max:191'])
                ->label('Demo password')
                ->help('Shown to visitors by design, so it is not stored as a secret.')
                ->field('text'),
            ExtensionSettingDefinition::make('verification_ttl_minutes', 'verification_ttl_minutes', 60, ['required', 'integer', 'min:5', 'max:1440'])
                ->label('Verification link lifetime (minutes)')
                ->help('Used when the visitor verifies their email first (password hidden, or optional and left blank).')
                ->field('number'),
            ExtensionSettingDefinition::make('rate_limit_per_minute', 'rate_limit_per_minute', 5, ['required', 'integer', 'min:1', 'max:100'])
                ->label('Sign-up rate limit (per IP, per minute)')
                ->help('How many registration attempts one IP address may make each minute before it gets a "too many attempts" response.')
                ->field('number'),
        ];

        // How each field of the sign-up form behaves: required, optional or hidden. The
        // /panel/drift-register screen and Admin -> Extensions edit the same values.
        $modeOptions = [
            ['value' => 'required', 'label' => 'Required'],
            ['value' => 'optional', 'label' => 'Optional'],
            ['value' => 'hidden', 'label' => 'Hidden'],
        ];
        $fieldLabels = [
            'first_name' => ['First name', 'Hidden or left blank: the account is created with the name "User".'],
            'last_name' => ['Last name', 'Hidden or left blank: the account is created with the name "User".'],
            'company' => ['Company name', 'Stored by this extension; the Panel itself has no company field.'],
            'phone' => ['Phone number', 'Stored by this extension; the Panel itself has no phone field.'],
            'username' => ['Username', 'Hidden or left blank: one is made from the email address.'],
            'password' => ['Password', 'Required: the account is created at once. Hidden, or optional and left blank: the visitor verifies their email first, then sets a password from the emailed link.'],
            'password_confirm' => ['Retype password', 'Only applies while the password field is shown.'],
            'discord' => ['Discord sign-up', 'Needs Discord sign-in to be configured below. Required: the visitor must connect a Discord account to register.'],
            'google' => ['Google sign-up', 'Needs Google sign-in to be configured below. Required: the visitor must connect a Google account to register.'],
        ];
        foreach ($fieldLabels as $field => [$label, $help]) {
            $base[] = ExtensionSettingDefinition::make("field_{$field}", "field_{$field}", RegistrationSettings::FIELDS[$field], ['required', 'in:required,optional,hidden'])
                ->label($label)
                ->help($help)
                ->field('select', $modeOptions);
        }

        // Single sign-on credentials. Client secrets are encrypted and never sent to the browser;
        // the /panel/drift-register screen and Admin -> Extensions edit the same values.
        foreach ([['google', 'Google'], ['discord', 'Discord']] as [$key, $label]) {
            $definitions[] = ExtensionSettingDefinition::make("sso_{$key}_enabled", "sso_{$key}_enabled", false, ['boolean'])
                ->label("Enable {$label} sign-in")
                ->help("Needs the client ID and client secret below.")
                ->field('toggle');
            $definitions[] = ExtensionSettingDefinition::make("sso_{$key}_client_id", "sso_{$key}_client_id", '', ['nullable', 'string', 'max:255'])
                ->label("{$label} client ID")
                ->field('text');
            $definitions[] = ExtensionSettingDefinition::make("sso_{$key}_client_secret", "sso_{$key}_client_secret", '', ['nullable', 'string', 'max:500'])
                ->label("{$label} client secret")
                ->help('Leave blank to keep the saved secret.')
                ->secret();
        }
        $definitions[] = ExtensionSettingDefinition::make('turnstile_enabled', 'turnstile_enabled', false, ['boolean'])
            ->label('Protect registration with Cloudflare Turnstile')
            ->help('Requires a site key and secret key. The secret is kept server-side.')
            ->field('toggle');
        $definitions[] = ExtensionSettingDefinition::make('turnstile_site_key', 'turnstile_site_key', '', ['nullable', 'string', 'max:255'])
            ->label('Cloudflare Turnstile site key')
            ->field('text');
        $definitions[] = ExtensionSettingDefinition::make('turnstile_secret_key', 'turnstile_secret_key', '', ['nullable', 'string', 'max:500'])
            ->label('Cloudflare Turnstile secret key')
            ->help('Leave blank to keep the saved secret.')
            ->secret();
        $this->registerSettings(new ExtensionSettingsDefinition($this->settings(), [...$base, ...$definitions]));

        // The limit is an admin setting, so it is read on every request instead of being
        // fixed in the route file. Routes use it as "throttle:drift-register".
        RateLimiter::for('drift-register', function (Request $request) {
            return Limit::perMinute(app(RegistrationSettings::class)->rateLimit())
                ->by('drift-register|' . $request->ip());
        });

        // Public routes (login page): /extensions/drift-register/...
        $this->registerWebRoutes($this->extensionPath('routes', 'web.php'));

        // Standalone page: /register (the prefix is declared in extension.json routes.root).
        $this->registerRootRoutes($this->extensionPath('routes', 'root.php'));

        // Signed-in web routes (account linking): /extensions/drift-register/sso/{provider}/link
        $this->registerAuthenticatedWebRoutes($this->extensionPath('routes', 'authenticated.php'));

        // routes/admin.php and routes/client.php -> /api/admin|client/extensions/drift-register/...
        $this->registerApiRoutes();

        $this->loadExtensionMigrations();
        $this->loadExtensionViews();
    }
}
