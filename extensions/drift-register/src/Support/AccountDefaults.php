<?php

namespace DriftRegister\Support;

use Illuminate\Support\Facades\DB;
use Pterodactyl\Models\User;

/**
 * Fallback values for Panel account fields that the sign-up form hides or leaves blank.
 * The Panel itself always needs a username, first name and last name.
 */
final class AccountDefaults
{
    private const PENDING_TABLE = 'ext_drift_register_pending_registrations';

    public static function name(string $value, string $fallback = 'User'): string
    {
        $value = trim($value);

        return mb_substr($value !== '' ? $value : $fallback, 0, 191);
    }

    /**
     * A free username that passes the Panel's rule: a-z 0-9 _ . - , starting and ending
     * alphanumeric, 3+ characters. With $checkPending the names held by unverified
     * sign-ups are avoided too.
     */
    public static function uniqueUsername(string $seed, bool $checkPending = false): string
    {
        $base = preg_replace('/[^a-z0-9_.-]/', '', mb_strtolower($seed)) ?? '';
        $base = preg_replace('/^[^a-z0-9]+|[^a-z0-9]+$/', '', $base) ?? '';
        // Cut first, then trim again: the cut can leave a "-", "." or "_" at the end.
        $base = preg_replace('/[^a-z0-9]+$/', '', substr($base, 0, 40)) ?? '';
        if (strlen($base) < 3) {
            $base = 'user' . $base;
        }

        $candidate = $base;
        for ($attempt = 0; $attempt < 20; ++$attempt) {
            if (! self::taken($candidate, $checkPending)) {
                return $candidate;
            }
            $candidate = $base . random_int(100, 99999);
        }

        return $base . bin2hex(random_bytes(4));
    }

    /** The part of an email address before the @, as a username seed. */
    public static function seedFromEmail(string $email): string
    {
        return explode('@', $email, 2)[0];
    }

    private static function taken(string $username, bool $checkPending): bool
    {
        if (User::query()->where('username', $username)->exists()) {
            return true;
        }

        return $checkPending && DB::table(self::PENDING_TABLE)
            ->where('username', $username)
            ->where('expires_at', '>', now())
            ->exists();
    }
}
