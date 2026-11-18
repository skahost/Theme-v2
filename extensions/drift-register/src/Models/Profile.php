<?php

namespace DriftRegister\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Pterodactyl\Models\User;

/**
 * Extra sign-up details the Panel's own user table has no columns for.
 *
 * @property int $id
 * @property int $user_id
 * @property string|null $company
 * @property string|null $phone
 * @property string|null $notes
 */
class Profile extends Model
{
    protected $table = 'ext_drift_register_profiles';

    protected $fillable = ['user_id', 'company', 'phone', 'notes'];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }
}
