<?php

declare(strict_types=1);

namespace Vizion\Http\Middleware;

use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Pterodactyl\Services\Extensions\ExtensionManifest;
use Pterodactyl\Services\Extensions\ExtensionRepository;
use Symfony\Component\HttpFoundation\Response;
use UnexpectedValueException;

final class FilterBundledExtensionsFromAdminList
{
    public function __construct(private readonly ExtensionRepository $extensions) {}

    public function handle(Request $request, Closure $next): Response
    {
        foreach ([storage_path('framework/cache'), storage_path('framework/cache/data')] as $directory) {
            if (! is_dir($directory)) {
                @mkdir($directory, 0775, true);
            }
        }
        $response = $next($request);
        if (
            ! $request->isMethod('GET')
            || ! $request->is('api/admin/extensions')
            || $request->query('vizion_bundle') === '1'
            || ! $response instanceof JsonResponse
        ) {
            return $response;
        }

        $payload = $response->getData(true);
        if (! is_array($payload) || ! is_array($payload['data'] ?? null)) {
            return $response;
        }

        $ids = [
            'demo', 'drift-register', 'drift-mod', 'drift-plugins', 'drift-properties',
            'egg-changer', 'mc-console', 'mc-players', 'mc-version', 'mc-world',
            'mcp', 'monitoring', 'node-resources', 'palworld-tools', 'server-creator',
            'server-expiry', 'server-groups', 'server-splitter', 'sso', 'subdomains',
            'terraria-tools', 'valheim-tools', 'votifier-tester',
        ];

        $payload['data'] = array_values(array_filter(
            $payload['data'],
            static function (mixed $extension) use ($ids): bool {
                if (! is_array($extension)) {
                    return true;
                }
                $id = (string) ($extension['id'] ?? '');
                return ! in_array($id, $ids, true)
                    && ! str_starts_with(strtolower($id), 'drift-');
            },
        ));
        $response->setData($payload);

        return $response;
    }
}
