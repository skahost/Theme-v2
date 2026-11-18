<?php

namespace DriftRegister\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * A sign-up waiting for its email to be verified. The Panel user is only created
 * once the link is opened, so unverified addresses never become accounts.
 *
 * @property string $email
 * @property string $username
 * @property string $name_first
 * @property string $name_last
 * @property string|null $company
 * @property string|null $phone
 * @property list<array{provider: string, id: string, email: string|null}>|null $sso
 * @property string $token_hash
 * @property \Illuminate\Support\Carbon $expires_at
 */
class PendingRegistration extends Model
{
    protected $table = 'ext_drift_register_pending_registrations';

    protected $fillable = ['email', 'username', 'name_first', 'name_last', 'company', 'phone', 'sso', 'token_hash', 'expires_at'];

    protected $hidden = ['token_hash'];

    protected function casts(): array
    {
        return ['expires_at' => 'datetime', 'sso' => 'array'];
    }
}
