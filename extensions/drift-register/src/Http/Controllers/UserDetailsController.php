<?php

namespace DriftRegister\Http\Controllers;

use DriftRegister\Models\Profile;
use DriftRegister\Models\Signup;
use DriftRegister\Models\SsoIdentity;
use DriftRegister\Sso\SsoProvider;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Collection;
use Pterodactyl\Models\User;

/**
 * What this extension knows about a Panel user, for the "Drift Register" tab of /panel/users/{id}.
 * Root administrators only (routes/admin.php).
 */
class UserDetailsController extends Controller
{
    /** Save the company name, phone number and admin notes. The Panel's own user fields are not touched. */
    public function update(Request $request, int $userId): JsonResponse
    {
        $model = User::query()->findOrFail($userId);

        $data = $request->validate([
            'company' => ['sometimes', 'nullable', 'string', 'max:191'],
            'phone' => ['sometimes', 'nullable', 'string', 'max:32'],
            'notes' => ['sometimes', 'nullable', 'string', 'max:2000'],
        ]);

        $values = [];
        foreach ($data as $field => $value) {
            $value = trim((string) $value);
            $values[$field] = $value !== '' ? $value : null;
        }
        if ($values !== []) {
            Profile::query()->updateOrCreate(['user_id' => $model->id], $values);
        }

        return $this->one($model);
    }

    public function show(int $userId): JsonResponse
    {
        return $this->one(User::query()->findOrFail($userId));
    }

    private function one(User $user): JsonResponse
    {
        return new JsonResponse(['data' => $this->rows(collect([$user]))[0]]);
    }

    /**
     * The user's Discord account, or null when none is linked. The username can be null for an
     * account linked before usernames were saved; it fills in at the next Discord sign-in.
     *
     * @param  Collection<int, SsoIdentity>|null  $identities
     * @return array{username: string|null, email: string|null}|null
     */
    private function discord(?Collection $identities): ?array
    {
        $identity = $identities?->firstWhere('provider', 'discord');

        return $identity === null ? null : ['username' => $identity->username, 'email' => $identity->email];
    }

    /** @return array{method: string, ip_address: string|null, user_agent: string|null, at: string|null}|null */
    private function signup(?Signup $signup): ?array
    {
        return $signup === null ? null : [
            'method' => $signup->method,
            'ip_address' => $signup->ip_address,
            'user_agent' => $signup->user_agent,
            'at' => $signup->created_at?->toIso8601String(),
        ];
    }

    /**
     * @param  Collection<int, User>  $users
     * @return list<array<string, mixed>>
     */
    private function rows(Collection $users): array
    {
        $ids = $users->pluck('id')->all();
        $profiles = Profile::query()->whereIn('user_id', $ids)->get()->keyBy('user_id');
        $identities = SsoIdentity::query()->whereIn('user_id', $ids)->get()->groupBy('user_id');
        $signups = Signup::query()->whereIn('user_id', $ids)->get()->keyBy('user_id');

        return $users->map(fn (User $user) => [
            'id' => $user->id,
            'username' => $user->username,
            'email' => $user->email,
            'first_name' => (string) $user->name_first,
            'last_name' => (string) $user->name_last,
            'root_admin' => (bool) $user->root_admin,
            'two_factor' => (bool) $user->use_totp,
            'uuid' => $user->uuid,
            'language' => $user->language,
            'created_at' => $user->created_at?->toIso8601String(),
            'company' => $profiles->get($user->id)?->company,
            'phone' => $profiles->get($user->id)?->phone,
            'notes' => $profiles->get($user->id)?->notes,
            'signup' => $this->signup($signups->get($user->id)),
            'discord' => $this->discord($identities->get($user->id)),
            'sso' => ($identities->get($user->id) ?? collect())->map(fn (SsoIdentity $identity) => [
                'provider' => $identity->provider,
                'label' => SsoProvider::find($identity->provider)?->label ?? $identity->provider,
                'email' => $identity->email,
                'username' => $identity->username,
                'display_name' => $identity->display_name,
                'avatar_url' => $identity->avatar_url,
                'linked_at' => $identity->created_at?->toIso8601String(),
                'last_login_at' => $identity->last_login_at?->toIso8601String(),
            ])->values()->all(),
        ])->values()->all();
    }
}
