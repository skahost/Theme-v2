<?php

namespace DriftRegister\Http\Controllers;

use DriftRegister\Models\SsoIdentity;
use DriftRegister\Settings\RegistrationSettings;
use DriftRegister\Sso\SsoProvider;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Pterodactyl\Models\User;

/** The signed-in user's own links (routes/client.php). */
class SsoConnectionsController extends Controller
{
    public function index(Request $request, RegistrationSettings $settings): JsonResponse
    {
        $user = $request->user();
        abort_unless($user instanceof User, 403);

        $linked = SsoIdentity::query()->where('user_id', $user->id)->get()->keyBy('provider');

        $providers = array_map(function (SsoProvider $provider) use ($settings, $linked) {
            $identity = $linked->get($provider->key);

            return [
                'provider' => $provider->key,
                'label' => $provider->label,
                'available' => $settings->ssoReady($provider->key),
                'linked' => $identity !== null,
                'email' => $identity?->email,
            ];
        }, SsoProvider::all());

        return (new JsonResponse(['providers' => $providers]))->header('Cache-Control', 'no-store');
    }

    public function destroy(Request $request, string $provider): JsonResponse
    {
        $user = $request->user();
        abort_unless($user instanceof User, 403);
        abort_unless(in_array($provider, SsoProvider::KEYS, true), 404);

        SsoIdentity::query()->where('user_id', $user->id)->where('provider', $provider)->delete();

        return new JsonResponse([], 204);
    }
}
