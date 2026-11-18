<?php

namespace DriftRegister\Http\Controllers;

use DriftRegister\Models\PendingRegistration;
use DriftRegister\Settings\RegistrationSettings;
use DriftRegister\Sso\SsoProvider;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/** Root administrators only (routes/admin.php). */
class ConfigController extends Controller
{
    public function show(RegistrationSettings $settings): JsonResponse
    {
        return new JsonResponse($this->payload($settings));
    }

    public function update(Request $request, RegistrationSettings $settings): JsonResponse
    {
        $rules = [
            'registration_enabled' => ['required', 'boolean'],
            'hide_discord_button' => ['sometimes', 'boolean'],
            'demo_mode' => ['sometimes', 'boolean'],
            'demo_autofill' => ['sometimes', 'boolean'],
            'demo_email' => ['sometimes', 'nullable', 'string', 'max:191'],
            'demo_password' => ['sometimes', 'nullable', 'string', 'max:191'],
            'fields' => ['required', 'array'],
            'verification_ttl_minutes' => ['required', 'integer', 'min:5', 'max:1440'],
            'rate_limit_per_minute' => ['required', 'integer', 'min:1', 'max:100'],
            'sso' => ['sometimes', 'array'],
        ];
        foreach (array_keys(RegistrationSettings::FIELDS) as $field) {
            $rules["fields.{$field}"] = ['required', 'string', Rule::in(RegistrationSettings::MODES)];
        }
        foreach (SsoProvider::KEYS as $key) {
            $rules["sso.{$key}"] = ['sometimes', 'array'];
            $rules["sso.{$key}.enabled"] = ['sometimes', 'boolean'];
            $rules["sso.{$key}.client_id"] = ['sometimes', 'nullable', 'string', 'max:255'];
            // Blank keeps the saved secret.
            $rules["sso.{$key}.client_secret"] = ['sometimes', 'nullable', 'string', 'max:500'];
        }

        $data = $request->validate($rules);

        // A provider can only be switched on once it has both credentials.
        $errors = [];
        foreach (SsoProvider::KEYS as $key) {
            $input = $data['sso'][$key] ?? null;
            if (! is_array($input) || ! ($input['enabled'] ?? false)) {
                continue;
            }

            // Check what will actually be stored: a submitted client ID (even a blank one) replaces the saved one.
            $clientId = array_key_exists('client_id', $input)
                ? trim((string) $input['client_id'])
                : $settings->ssoClientId($key);
            $hasSecret = trim((string) ($input['client_secret'] ?? '')) !== '' || $settings->ssoHasSecret($key);
            if ($clientId === '' || ! $hasSecret) {
                $errors["sso.{$key}.client_id"] = 'Enter the client ID and client secret before enabling this provider.';
            }
        }

        // A sign-up provider can only be shown or required once it is switched on and has
        // both credentials, counting what is being saved in this same request.
        foreach (SsoProvider::KEYS as $key) {
            if (($data['fields'][$key] ?? 'hidden') === 'hidden') {
                continue;
            }

            $input = $data['sso'][$key] ?? [];
            $enabled = array_key_exists('enabled', $input) ? (bool) $input['enabled'] : $settings->ssoEnabled($key);
            $clientId = array_key_exists('client_id', $input) ? trim((string) $input['client_id']) : $settings->ssoClientId($key);
            $hasSecret = trim((string) ($input['client_secret'] ?? '')) !== '' || $settings->ssoHasSecret($key);
            if (! $enabled || $clientId === '' || ! $hasSecret) {
                $label = SsoProvider::find($key)?->label ?? $key;
                $errors["fields.{$key}"] = "Set up and enable {$label} sign-in below before showing it on the sign-up form.";
            }
        }
        // Auto-fill needs both demo values, counting what is being saved in this same request.
        $demoOn = array_key_exists('demo_mode', $data) ? (bool) $data['demo_mode'] : $settings->demoMode();
        $demoAuto = array_key_exists('demo_autofill', $data) ? (bool) $data['demo_autofill'] : $settings->demoAutofill();
        $demoEmail = array_key_exists('demo_email', $data) ? trim((string) $data['demo_email']) : $settings->demoEmail();
        $demoPassword = array_key_exists('demo_password', $data) ? (string) $data['demo_password'] : $settings->demoPassword();
        if ($demoOn && $demoAuto) {
            if ($demoEmail === '') {
                $errors['demo_email'] = 'Enter the demo email to auto-fill the login form.';
            }
            if ($demoPassword === '') {
                $errors['demo_password'] = 'Enter the demo password to auto-fill the login form.';
            }
        }
        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }

        $settings->update([
            'registration_enabled' => (bool) $data['registration_enabled'],
            'hide_discord_button' => (bool) ($data['hide_discord_button'] ?? $settings->hideDiscordButton()),
            'demo_mode' => $demoOn,
            'demo_autofill' => $demoAuto,
            'demo_email' => $demoEmail,
            'demo_password' => $demoPassword,
            'verification_ttl_minutes' => (int) $data['verification_ttl_minutes'],
            'rate_limit_per_minute' => (int) $data['rate_limit_per_minute'],
        ]);

        $settings->updateModes($data['fields']);

        foreach (SsoProvider::KEYS as $key) {
            if (isset($data['sso'][$key]) && is_array($data['sso'][$key])) {
                $settings->updateSso($key, $data['sso'][$key]);
            }
        }

        return new JsonResponse($this->payload($settings));
    }

    /** @return array<string, mixed> */
    private function payload(RegistrationSettings $settings): array
    {
        return [
            ...$settings->all(),
            'sso' => array_combine(
                SsoProvider::KEYS,
                array_map(fn (string $key) => $settings->ssoAdmin($key), SsoProvider::KEYS),
            ),
            // "log" and "array" never deliver mail; the screen warns about it.
            'mail_driver' => (string) config('mail.default'),
            'pending_count' => PendingRegistration::query()->where('expires_at', '>', now())->count(),
        ];
    }
}
