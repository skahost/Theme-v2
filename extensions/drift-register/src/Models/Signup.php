<?php

namespace DriftRegister\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Http\Request;
use Pterodactyl\Models\User;
use Throwable;

/**
 * How and when a user's account was made here.
 *
 * @property int $id
 * @property int $user_id
 * @property string $method password, email, google or discord
 * @property string|null $ip_address
 * @property string|null $user_agent
 */
class Signup extends Model
{
    protected $table = 'ext_drift_register_signups';

    protected $fillable = ['user_id', 'method', 'ip_address', 'user_agent'];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    /** Remember how a user signed up. A failure here must never stop the sign-up itself. */
    public static function record(User $user, string $method, Request $request): void
    {
        try {
            $agent = trim((string) $request->userAgent());

            self::query()->updateOrCreate(['user_id' => $user->id], [
                'method' => $method,
                'ip_address' => $request->ip(),
                'user_agent' => $agent !== '' ? mb_substr($agent, 0, 255) : null,
            ]);
        } catch (Throwable $exception) {
            report($exception);
        }
    }
}
