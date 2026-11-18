<?php

namespace DriftRegister\Support;

use Pterodactyl\Contracts\Users\CreatesUsers;
use Pterodactyl\Models\User;
use Throwable;

/**
 * Creates a Panel user through the Panel's own service.
 *
 * The Panel sends its "set your password" email after it saves the account, so a mail
 * failure (mail not configured) can throw after the user already exists. That case is
 * tolerated, but only when the account did not exist before this call. Otherwise a failure
 * for any other reason could hand back someone else's existing account, and the caller
 * would then link a provider account or profile to it.
 */
final class AccountCreator
{
    /**
     * @param  array<string, string>  $data  Must include "email".
     *
     * @throws Throwable
     */
    public static function create(CreatesUsers $creator, array $data): User
    {
        $existed = User::query()->where('email', $data['email'])->exists();

        try {
            return $creator->create($data);
        } catch (Throwable $exception) {
            if ($existed) {
                throw $exception;
            }

            $user = User::query()->where('email', $data['email'])->first();
            if ($user === null) {
                throw $exception;
            }

            report($exception);

            return $user;
        }
    }
}
