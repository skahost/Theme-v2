<?php

namespace DriftRegister\Sso;

use DriftRegister\Models\SsoIdentity;
use DriftRegister\Settings\RegistrationSettings;
use Illuminate\Database\QueryException;
use Illuminate\Http\Request;
use Pterodactyl\Models\User;

/**
 * Google / Discord accounts a visitor has proven they own while filling in the sign-up
 * form. They live in the browser session for thirty minutes and are linked to the new
 * account once it exists.
 *
 * @phpstan-type Profile array{id: string, email: string, email_verified: bool, username: string, first: string, last: string, name?: string, avatar?: string}
 */
final class SsoConnections
{
    public const SESSION_KEY = 'drift_register.connected';

    private const TTL_SECONDS = 1800;

    public function __construct(private readonly RegistrationSettings $settings) {}

    /** @param  Profile  $profile */
    public function remember(Request $request, string $provider, array $profile): void
    {
        if (! $request->hasSession()) {
            return;
        }

        $all = $this->raw($request);
        $all[$provider] = [...$profile, 'expires' => time() + self::TTL_SECONDS];
        $request->session()->put(self::SESSION_KEY, $all);
    }

    /**
     * Connections that are still valid and whose provider is still offered on the form.
     *
     * @return array<string, Profile>
     */
    public function all(Request $request): array
    {
        $usable = [];
        foreach ($this->raw($request) as $provider => $profile) {
            if (
                is_array($profile)
                && (int) ($profile['expires'] ?? 0) >= time()
                && in_array($provider, SsoProvider::KEYS, true)
                && $this->settings->mode($provider) !== 'hidden'
            ) {
                unset($profile['expires']);
                $usable[$provider] = $profile;
            }
        }

        return $usable;
    }

    public function forget(Request $request): void
    {
        if ($request->hasSession()) {
            $request->session()->forget(self::SESSION_KEY);
        }
    }

    /**
     * Link provider accounts to a user. One that is already taken, or already linked for
     * this provider, is skipped.
     *
     * @param  list<array{provider: string, id: string, email: string|null, username?: string|null, name?: string|null, avatar?: string|null}>  $identities
     */
    public function link(User $user, array $identities): void
    {
        foreach ($identities as $identity) {
            $provider = (string) ($identity['provider'] ?? '');
            $id = (string) ($identity['id'] ?? '');
            if ($id === '' || ! in_array($provider, SsoProvider::KEYS, true)) {
                continue;
            }

            $taken = SsoIdentity::query()->where('provider', $provider)->where('provider_user_id', $id)->exists()
                || SsoIdentity::query()->where('user_id', $user->id)->where('provider', $provider)->exists();
            if ($taken) {
                continue;
            }

            try {
                SsoIdentity::query()->create([
                    'user_id' => $user->id,
                    'provider' => $provider,
                    'provider_user_id' => $id,
                    'email' => self::email($identity['email'] ?? null),
                    'username' => self::username($provider, $identity['username'] ?? null),
                    ...self::details($identity),
                    'last_login_at' => now(),
                ]);
            } catch (QueryException $exception) {
                // Another request linked the same account a moment ago. The account itself
                // already exists, so one failed link must not fail the whole sign-up.
                report($exception);
            }
        }
    }

    /**
     * What the form needs to remember a connection: provider, id, email, username, name and picture.
     *
     * @param  array<string, Profile>  $connected
     * @return list<array{provider: string, id: string, email: string|null, username: string|null, name: string|null, avatar: string|null}>
     */
    public static function identities(array $connected): array
    {
        $out = [];
        foreach ($connected as $provider => $profile) {
            $out[] = [
                'provider' => (string) $provider,
                'id' => $profile['id'],
                'email' => self::email($profile['email'] ?? null),
                'username' => self::username((string) $provider, $profile['username'] ?? null),
                'name' => self::details($profile)['display_name'],
                'avatar' => self::details($profile)['avatar_url'],
            ];
        }

        return $out;
    }

    /** @return array<string, mixed> */
    private function raw(Request $request): array
    {
        if (! $request->hasSession()) {
            return [];
        }

        $all = $request->session()->get(self::SESSION_KEY);

        return is_array($all) ? $all : [];
    }

    /** An email address that fits the column, or null. */
    public static function email(mixed $email): ?string
    {
        $email = trim((string) $email);

        return $email !== '' && mb_strlen($email) <= 191 ? $email : null;
    }

    /**
     * The username worth keeping. Only Discord has one a person recognises; a Google "username"
     * is just the start of the email address.
     */
    public static function username(string $provider, mixed $username): ?string
    {
        $username = trim((string) $username);

        return $provider === 'discord' && $username !== '' ? mb_substr($username, 0, 191) : null;
    }

    /**
     * The display name and picture worth keeping for a provider profile. Accepts a provider
     * profile (name, avatar) or a remembered identity (name, avatar) alike.
     *
     * @param  array<string, mixed>  $profile
     * @return array{display_name: string|null, avatar_url: string|null}
     */
    public static function details(array $profile): array
    {
        $name = trim((string) ($profile['name'] ?? ''));
        $avatar = trim((string) ($profile['avatar'] ?? ''));

        return [
            'display_name' => $name !== '' ? mb_substr($name, 0, 191) : null,
            'avatar_url' => str_starts_with($avatar, 'https://') && mb_strlen($avatar) <= 255 ? $avatar : null,
        ];
    }
}
