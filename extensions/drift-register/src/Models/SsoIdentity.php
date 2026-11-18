<?php

namespace DriftRegister\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Pterodactyl\Models\User;

/**
 * A Google or Discord account linked to a Panel user. SSO sign-in only ever matches on
 * (provider, provider_user_id), never on email, so a matching address alone cannot take
 * over an existing account.
 *
 * @property int $id
 * @property int $user_id
 * @property string $provider
 * @property string $provider_user_id
 * @property string|null $email
 * @property string|null $username
 * @property string|null $display_name
 * @property string|null $avatar_url
 * @property \Illuminate\Support\Carbon|null $last_login_at
 */
class SsoIdentity extends Model
{
    protected $table = 'ext_drift_register_sso_identities';

    protected $fillable = ['user_id', 'provider', 'provider_user_id', 'email', 'username', 'display_name', 'avatar_url', 'last_login_at'];

    protected $casts = ['last_login_at' => 'datetime'];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }
}
