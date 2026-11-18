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

        $manifest = $this->extensions->discovered()->get('vizion');
        if (! $manifest instanceof ExtensionManifest) {
            throw new UnexpectedValueException('The Vizion extension manifest is unavailable.');
        }

        $indexPath = $manifest->path('resources', 'packages', 'index.json');
        if (! is_file($indexPath)) {
            throw new UnexpectedValueException('The Vizion bundle index is missing.');
        }
        $contents = file_get_contents($indexPath);
        if (! is_string($contents)) {
            throw new UnexpectedValueException('The Vizion bundle index could not be read.');
        }
        $packages = json_decode($contents, true, 512, JSON_THROW_ON_ERROR);
        if (! is_array($packages)) {
            throw new UnexpectedValueException('The Vizion bundle index is invalid.');
        }

        $ids = [];
        foreach ($packages as $package) {
            if (! is_array($package) || ! is_string($package['id'] ?? null)) {
                throw new UnexpectedValueException('The Vizion bundle index contains an invalid entry.');
            }
            $ids[] = $package['id'];
        }

        $payload['data'] = array_values(array_filter(
            $payload['data'],
            static fn (mixed $extension): bool => ! is_array($extension)
                || ! in_array($extension['id'] ?? null, $ids, true),
        ));
        $response->setData($payload);

        return $response;
    }
}
