<?php

namespace DriftRegister\Sso;

use DriftRegister\Settings\RegistrationSettings;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

/**
 * The OAuth 2.0 authorization-code flow for the supported providers. The state, the
 * mode (login, register or link) and the PKCE verifier live in the browser session, are
 * valid for one attempt only, and expire after ten minutes.
 */
final class SsoService
{
    public const SESSION_KEY = 'drift_register.sso';

    private const TTL_SECONDS = 600;

    public function __construct(private readonly RegistrationSettings $settings) {}

    public function callbackUrl(SsoProvider $provider): string
    {
        return route('extensions.drift-register.web.sso.callback', ['provider' => $provider->key]);
    }

    /** Remember the attempt in the session and return the provider's authorization URL. */
    public function begin(Request $request, SsoProvider $provider, string $mode, ?int $userId = null): string
    {
        $state = Str::random(40);
        $verifier = Str::random(64);

        $request->session()->put(self::SESSION_KEY, [
            'state' => $state,
            'provider' => $provider->key,
            'mode' => $mode,
            'user_id' => $userId,
            'verifier' => $verifier,
            'expires' => time() + self::TTL_SECONDS,
        ]);

        $query = [
            'client_id' => $this->settings->ssoClientId($provider->key),
            'redirect_uri' => $this->callbackUrl($provider),
            'response_type' => 'code',
            'scope' => $provider->scope,
            'state' => $state,
        ];

        if ($provider->pkce) {
            $query['code_challenge'] = rtrim(strtr(base64_encode(hash('sha256', $verifier, true)), '+/', '-_'), '=');
            $query['code_challenge_method'] = 'S256';
        }

        if ($provider->key === 'google') {
            $query['prompt'] = 'select_account';
        }

        return $provider->authorizeUrl . '?' . http_build_query($query, '', '&', PHP_QUERY_RFC3986);
    }

    /**
     * Check the returned state, trade the code for a token and read the profile.
     *
     * @param  array<string, mixed>|null  $stored  The session entry written by begin(), already removed from the session.
     * @return array{id: string, email: string, email_verified: bool, username: string, first: string, last: string}
     *
     * @throws SsoException
     */
    public function profileFromCallback(Request $request, SsoProvider $provider, ?array $stored): array
    {
        if ($request->query('error') !== null) {
            throw new SsoException('denied');
        }

        $state = (string) $request->query('state', '');
        $expected = (string) ($stored['state'] ?? '');
        if (
            $expected === ''
            || ! hash_equals($expected, $state)
            || ($stored['provider'] ?? null) !== $provider->key
            || (int) ($stored['expires'] ?? 0) < time()
        ) {
            throw new SsoException('invalid_state');
        }

        $code = (string) $request->query('code', '');
        if ($code === '') {
            throw new SsoException('failed');
        }

        $form = [
            'client_id' => $this->settings->ssoClientId($provider->key),
            'client_secret' => $this->settings->ssoClientSecret($provider->key),
            'grant_type' => 'authorization_code',
            'code' => $code,
            'redirect_uri' => $this->callbackUrl($provider),
        ];
        if ($provider->pkce) {
            $form['code_verifier'] = (string) ($stored['verifier'] ?? '');
        }

        try {
            $token = Http::asForm()->acceptJson()->withUserAgent('DriftRegister (Pterodactyl extension)')
                ->timeout(10)->post($provider->tokenUrl, $form);
            $accessToken = $token->successful() ? $token->json('access_token') : null;
            if (! is_string($accessToken) || $accessToken === '') {
                throw new SsoException('failed');
            }

            $user = Http::withToken($accessToken)->acceptJson()->withUserAgent('DriftRegister (Pterodactyl extension)')
                ->timeout(10)->get($provider->userUrl);
            if (! $user->successful() || ! is_array($user->json())) {
                throw new SsoException('failed');
            }
        } catch (ConnectionException) {
            throw new SsoException('failed');
        }

        $profile = $provider->profile($user->json());
        if ($profile['id'] === '') {
            throw new SsoException('failed');
        }

        return $profile;
    }
}
