<?php

declare(strict_types=1);

namespace Vizion\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Pterodactyl\Contracts\Extensions\InstallsExtensions;
use Pterodactyl\Contracts\Extensions\SetsExtensionEnabled;
use Pterodactyl\Contracts\Themes\ActivatesThemes;
use Pterodactyl\Services\Extensions\ExtensionManifest;
use Pterodactyl\Services\Extensions\ExtensionRepository;
use Pterodactyl\Services\Themes\ThemeRepository;

final class BundleController
{
    public function reset(
        ExtensionRepository $extensions,
        ThemeRepository $themes,
    ): JsonResponse {
        $manifest = $extensions->discovered()->get('vizion');
        abort_unless($manifest instanceof ExtensionManifest, 404, 'The Vizion extension is not installed.');

        $settings = $extensions->settings('vizion');
        $defaults = [
            'accent' => '#8b5cf6',
            'sidebar' => 'left',
            'density' => 'comfortable',
            'blur' => 16,
            'logoHeight' => 48,
            'favicon' => null,
            'metaTitle' => '',
            'metaDescription' => '',
            'seoBanner' => null,
            'allowIndexing' => false,
            'metaThemeColor' => '#101114',
            'baseColor' => '#21163a',
            'socialLinks' => [],
            'discordEnabled' => false,
            'discordGuildId' => '',
            'discordInvite' => '',
            'announcement' => '',
            'announcementEnabled' => false,
            'announcementPosition' => 'top',
            'announcementColor' => '#21163a',
            'announcementIcon' => 'megaphone',
            'announcementDismissible' => true,
            'ctaColor' => '#8b5cf6',
            'ctaTitle' => '',
            'ctaUrl' => '',
            'glassmorphism' => true,
            'dashboardBackground' => false,
            'font' => 'inter',
            'tagline' => 'Every server, one place.',
            'liveStats' => true,
        ];

        foreach ($defaults as $key => $value) {
            foreach (['set', 'put', 'forget'] as $method) {
                if (! is_callable([$settings, $method])) {
                    continue;
                }
                try {
                    if ($method === 'forget') {
                        $settings->{$method}($key);
                        continue;
                    }
                    $settings->{$method}($key, $value);
                    break;
                } catch (\Throwable) {
                    // Continue with the next supported settings repository API.
                }
            }
        }

        $cacheDirectories = [
            storage_path('framework/cache'),
            storage_path('framework/cache/data'),
            storage_path('framework/views'),
            storage_path('framework/sessions'),
        ];
        foreach ($cacheDirectories as $directory) {
            if (! is_dir($directory)) {
                @mkdir($directory, 0775, true);
            }
        }

        if (method_exists($this, 'clearLaravelCaches')) {
            $this->clearLaravelCaches();
        }

        return new JsonResponse([
            'data' => [
                'reset' => true,
                'themeActive' => $themes->active() === 'vizion',
            ],
        ]);
    }

    public function masterStatus(ExtensionRepository $extensions, ThemeRepository $themes): JsonResponse
    {
        return new JsonResponse([
            'data' => [
                'active' => $themes->active() === 'vizion',
                'extensionEnabled' => (bool) ($extensions->records()->get('vizion')->enabled ?? false),
            ],
        ]);
    }

    public function install(
        Request $request,
        InstallsExtensions $installer,
        ExtensionRepository $extensions,
    ): JsonResponse {
        $manifest = $extensions->discovered()->get('vizion');
        abort_unless($manifest instanceof ExtensionManifest, 404, 'The Vizion extension is not installed.');

        $validated = $request->validate([
            'extension' => ['sometimes', 'string', 'regex:/^[a-z0-9-]+$/'],
        ]);
        $requestedId = $validated['extension'] ?? null;
        $packages = $this->bundledPackages($manifest);
        if ($requestedId !== null) {
            abort_unless(
                in_array($requestedId, array_column($packages, 'id'), true),
                404,
                sprintf('The bundled extension "%s" was not found.', $requestedId),
            );
        }

        $results = [];
        foreach ($packages as $package) {
            $id = $package['id'];
            if ($requestedId !== null && $id !== $requestedId) {
                continue;
            }

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

    public function setMaster(
        Request $request,
        InstallsExtensions $installer,
        SetsExtensionEnabled $state,
        ActivatesThemes $themes,
        ExtensionRepository $extensions,
        ThemeRepository $themeRepository,
    ): JsonResponse {
        $enabled = (bool) $request->validate(['enabled' => ['required', 'boolean']])['enabled'];
        $vizion = $extensions->discovered()->get('vizion');
        abort_unless($vizion instanceof ExtensionManifest, 404, 'The Vizion extension is not installed.');

        $packages = $this->bundledPackages($vizion);
        $ids = array_column($packages, 'id');

        if ($enabled) {
            if (! ($extensions->records()->get('vizion')->enabled ?? false)) {
                $state->setEnabled('vizion', true, $vizion);
            }

            foreach ($packages as $package) {
                $id = $package['id'];
                $path = $vizion->path('resources', 'packages', $package['file']);
                abort_unless(is_file($path), 500, sprintf('The bundled package "%s" is missing.', $id));

                $record = $extensions->records()->get($id);
                if (
                    $record === null
                    || ! $extensions->discovered()->has($id)
                    || version_compare($record->version, $package['version'], '<')
                ) {
                    $installer->install($path, false, $record !== null);
                }
            }

            $extensions->flushDiscovery();
            foreach ($this->dependencyOrder($ids, $extensions) as $id) {
                $manifest = $extensions->discovered()->get($id);
                abort_unless($manifest instanceof ExtensionManifest, 500, sprintf('The bundled extension "%s" is not installed.', $id));
                if (! ($extensions->records()->get($id)->enabled ?? false)) {
                    $state->setEnabled($id, true, $manifest);
                }
            }

            $themes->activate('vizion');
        } else {
            $themes->activate(ThemeRepository::CLASSIC);
            foreach (array_reverse($this->dependencyOrder($ids, $extensions, onlyInstalled: true)) as $id) {
                if ($extensions->records()->get($id)->enabled ?? false) {
                    $manifest = $extensions->discovered()->get($id);
                    abort_unless($manifest instanceof ExtensionManifest, 500, sprintf('The bundled extension "%s" is not installed.', $id));
                    $state->setEnabled($id, false, $manifest);
                }
            }
        }

        return $this->masterStatus($extensions, $themeRepository);
    }

    /**
     * @return list<array{id: string, version: string, file: string}>
     */
    private function bundledPackages(ExtensionManifest $manifest): array
    {
        $indexPath = $manifest->path('resources', 'packages', 'index.json');
        abort_unless(is_file($indexPath), 500, 'The Vizion package index is missing.');

        $packages = json_decode((string) file_get_contents($indexPath), true, 512, JSON_THROW_ON_ERROR);
        abort_unless(is_array($packages), 500, 'The Vizion package index is invalid.');
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
        }

        return $packages;
    }

    /**
     * @param list<string> $ids
     * @return list<string>
     */
    private function dependencyOrder(array $ids, ExtensionRepository $extensions, bool $onlyInstalled = false): array
    {
        $remaining = array_fill_keys(
            array_values(array_filter($ids, fn (string $id): bool => ! $onlyInstalled || $extensions->discovered()->has($id))),
            true,
        );
        $ordered = [];

        while ($remaining !== []) {
            $progress = false;
            foreach (array_keys($remaining) as $id) {
                $manifest = $extensions->discovered()->get($id);
                abort_unless($manifest instanceof ExtensionManifest, 500, sprintf('The bundled extension "%s" is not installed.', $id));

                $dependencies = array_intersect(array_keys($manifest->dependencies()), array_keys($remaining));
                if ($dependencies !== []) {
                    continue;
                }

                $ordered[] = $id;
                unset($remaining[$id]);
                $progress = true;
            }

            abort_unless($progress, 500, 'The bundled extensions contain a dependency cycle.');
        }

        return $ordered;
    }
}
