<?php

namespace DriftRegister\Sso;

/**
 * The OAuth 2.0 endpoints and profile mapping of one supported provider.
 *
 * @phpstan-type Profile array{id: string, email: string, email_verified: bool, username: string, first: string, last: string, name?: string, avatar?: string}
 */
final class SsoProvider
{
    public const KEYS = ['google', 'discord'];

    private function __construct(
        public readonly string $key,
        public readonly string $label,
        public readonly string $authorizeUrl,
        public readonly string $tokenUrl,
        public readonly string $userUrl,
        public readonly string $scope,
        public readonly bool $pkce,
    ) {}

    public static function find(string $key): ?self
    {
        return match ($key) {
            'google' => new self(
                'google',
                'Google',
                'https://accounts.google.com/o/oauth2/v2/auth',
                'https://oauth2.googleapis.com/token',
                'https://openidconnect.googleapis.com/v1/userinfo',
                'openid email profile',
                true,
            ),
            'discord' => new self(
                'discord',
                'Discord',
                'https://discord.com/oauth2/authorize',
                'https://discord.com/api/oauth2/token',
                'https://discord.com/api/users/@me',
                'identify email',
                false,
            ),
            default => null,
        };
    }

    /** @return list<self> */
    public static function all(): array
    {
        return array_values(array_filter(array_map(fn (string $key) => self::find($key), self::KEYS)));
    }

    /**
     * @param  array<string, mixed>  $raw  The provider's user-info response.
     * @return array{id: string, email: string, email_verified: bool, username: string, first: string, last: string, name?: string, avatar?: string}
     */
    public function profile(array $raw): array
    {
        $email = mb_strtolower(trim((string) ($raw['email'] ?? '')));

        if ($this->key === 'google') {
            $verified = $raw['email_verified'] ?? false;

            return [
                'id' => (string) ($raw['sub'] ?? ''),
                'email' => $email,
                'email_verified' => $verified === true || $verified === 'true',
                'username' => $email !== '' ? explode('@', $email, 2)[0] : '',
                'first' => trim((string) ($raw['given_name'] ?? '')),
                'last' => trim((string) ($raw['family_name'] ?? '')),
                'name' => trim((string) ($raw['name'] ?? '')),
                'avatar' => self::httpsUrl($raw['picture'] ?? null),
            ];
        }

        $username = trim((string) ($raw['username'] ?? ''));
        $display = trim((string) ($raw['global_name'] ?? ''));

        return [
            'id' => (string) ($raw['id'] ?? ''),
            'email' => $email,
            'email_verified' => ($raw['verified'] ?? false) === true,
            'username' => $username,
            'first' => $display !== '' ? $display : $username,
            'last' => '',
            'name' => $display !== '' ? $display : $username,
            'avatar' => $this->discordAvatar((string) ($raw['id'] ?? ''), $raw['avatar'] ?? null),
        ];
    }

    /** An https link worth showing as a picture, or an empty string. */
    private static function httpsUrl(mixed $url): string
    {
        $url = trim((string) $url);

        return str_starts_with($url, 'https://') && mb_strlen($url) <= 255 ? $url : '';
    }

    private function discordAvatar(string $id, mixed $hash): string
    {
        $hash = trim((string) $hash);

        return $id !== '' && preg_match('/^[A-Za-z0-9_]{1,64}$/', $hash) === 1
            ? "https://cdn.discordapp.com/avatars/{$id}/{$hash}.png?size=128"
            : '';
    }
}
