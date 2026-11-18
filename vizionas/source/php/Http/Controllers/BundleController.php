<?php

declare(strict_types=1);

namespace Vizion\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Pterodactyl\Contracts\Extensions\InstallsExtensions;
use Pterodactyl\Services\Extensions\ExtensionManifest;
use Pterodactyl\Services\Extensions\ExtensionRepository;

final class BundleController
{
    public function install(InstallsExtensions $installer, ExtensionRepository $extensions): JsonResponse
    {
        $manifest = $extensions->discovered()->get('vizion');
        abort_unless($manifest instanceof ExtensionManifest, 404, 'The Vizion extension is not installed.');

        $indexPath = $manifest->path('resources', 'packages', 'index.json');
        abort_unless(is_file($indexPath), 500, 'The Vizion package index is missing.');

        $packages = json_decode((string) file_get_contents($indexPath), true, 512, JSON_THROW_ON_ERROR);
        abort_unless(is_array($packages), 500, 'The Vizion package index is invalid.');

        $results = [];
        foreach ($packages as $package) {
            abort_unless(
                is_array($package)
                    && is_string($package['id'] ?? null)
                    && is_string($package['version'] ?? null)
                    && is_string($package['file'] ?? null)
                    && basename($package['file']) === $package['file'],
                500,
                'The Vizion package index contains an invalid entry.'
            );

            $id = $package['id'];
            $version = $package['version'];
            $path = $manifest->path('resources', 'packages', $package['file']);
            abort_unless(is_file($path), 500, sprintf('The bundled package "%s" is missing.', $id));

            $record = $extensions->records()->get($id);
            if (
                $record !== null
                && $extensions->discovered()->has($id)
                && version_compare($record->version, $version, '>=')
            ) {
                $results[] = [
                    'id' => $id,
                    'version' => $record->version,
                    'status' => 'current',
                    'enabled' => $record->enabled,
                ];

                continue;
            }

            $wasEnabled = (bool) ($record->enabled ?? false);
            $installed = $installer->install($path, $wasEnabled, $record !== null);

            $results[] = [
                'id' => $id,
                'version' => $installed->version,
                'status' => $record === null ? 'installed' : 'updated',
                'enabled' => $wasEnabled,
            ];
        }

        return new JsonResponse(['data' => $results]);
    }
}
