<?php

declare(strict_types=1);

namespace Vizion\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Pterodactyl\Services\Extensions\ExtensionManifest;
use Pterodactyl\Services\Extensions\ExtensionRepository;
use Pterodactyl\Services\Themes\ThemeRepository;
use Symfony\Component\HttpFoundation\Response;
use UnexpectedValueException;

final class VizionThemeGate
{
    public function __construct(
        private readonly ExtensionRepository $extensions,
        private readonly ThemeRepository $themes,
    ) {}

    public function handle(Request $request, Closure $next): Response
    {
        $route = $request->route();
        $routeName = is_object($route) && method_exists($route, 'getName') ? $route->getName() : null;
        $path = trim($request->path(), '/');
        $segments = explode('/', $path);
        $themeActive = $this->themes->active() === 'vizion';

        if (
            ! $themeActive
            && in_array('extensions', $segments, true)
            && $this->isBundledRequest($path, $routeName, $this->bundledIds())
        ) {
            abort(404);
        }

        $response = $next($request);
        if (! $themeActive) {
            return $response;
        }

        $contentType = $response->headers->get('Content-Type', '');
        $body = $response->getContent();
        if (! str_contains(strtolower($contentType), 'text/html') || ! is_string($body)) {
            return $response;
        }

        $settings = $this->extensions->settings('vizion');
        $indexing = $settings->get('allowIndexing', false) === true;
        $updated = $this->replaceMeta(
            $body,
            'name',
            'robots',
            $indexing ? 'index,follow' : 'noindex,nofollow',
        );
        $title = trim((string) $settings->get('metaTitle'));
        if ($title === '') {
            $title = (string) config('app.name', 'Pterodactyl');
        }
        $description = trim((string) $settings->get('metaDescription'));
        $banner = $settings->get('seoBanner');
        $image = is_string($banner) && $banner !== '' ? secure_url($banner) : null;
        $themeColor = (string) $settings->get('metaThemeColor', '#101114');
        $updated = $this->replaceTitle($updated, $title);
        $updated = $this->replaceMeta($updated, 'property', 'og:title', $title);
        $updated = $this->replaceMeta($updated, 'property', 'og:description', $description ?: null);
        $updated = $this->replaceMeta($updated, 'name', 'description', $description ?: null);
        $updated = $this->replaceMeta($updated, 'property', 'og:image', $image);
        $updated = $this->replaceMeta($updated, 'name', 'twitter:title', $title);
        $updated = $this->replaceMeta($updated, 'name', 'twitter:description', $description ?: null);
        $updated = $this->replaceMeta($updated, 'name', 'twitter:image', $image);
        $updated = $this->replaceMeta($updated, 'name', 'twitter:card', $image === null ? 'summary' : 'summary_large_image');
        $updated = $this->replaceMeta($updated, 'name', 'theme-color', $themeColor);
        $response->setContent($updated);

        return $response;
    }

    private function replaceMeta(string $html, string $attribute, string $name, ?string $content): string
    {
        $pattern = '/<meta\b(?=[^>]*\b'.preg_quote($attribute, '/').'=["\']'.preg_quote($name, '/').'["\'])[^>]*>/i';
        $replacement = $content === null
            ? ''
            : '<meta '.$attribute.'="'.htmlspecialchars($name, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8')
                .'" content="'.htmlspecialchars($content, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8').'">';
        $seen = false;
        $updated = preg_replace_callback(
            $pattern,
            static function (array $matches) use (&$seen, $replacement): string {
                if ($seen) {
                    return '';
                }
                $seen = true;

                return $replacement;
            },
            $html,
            -1,
            $count,
        );
        if (! is_string($updated)) {
            throw new UnexpectedValueException('Vizion could not update a server-rendered meta tag.');
        }
        if ($count === 0 && $content !== null) {
            $updated = preg_replace_callback(
                '/<head\b[^>]*>/i',
                static fn (array $matches): string => $matches[0].$replacement,
                $updated,
                1,
                $headCount,
            );
            if (! is_string($updated) || $headCount === 0) {
                throw new UnexpectedValueException('Vizion could not find the HTML head for SEO tags.');
            }
        }

        return $updated;
    }

    private function replaceTitle(string $html, string $title): string
    {
        $replacement = '<title>'.htmlspecialchars($title, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8').'</title>';
        $updated = preg_replace_callback(
            '/<title\b[^>]*>.*?<\/title>/is',
            static fn (array $matches): string => $replacement,
            $html,
            1,
            $count,
        );
        if (! is_string($updated)) {
            throw new UnexpectedValueException('Vizion could not update the server-rendered page title.');
        }
        if ($count === 0) {
            $updated = preg_replace_callback(
                '/<head\b[^>]*>/i',
                static fn (array $matches): string => $matches[0].$replacement,
                $updated,
                1,
                $headCount,
            );
            if (! is_string($updated) || $headCount === 0) {
                throw new UnexpectedValueException('Vizion could not find the HTML head for the page title.');
            }
        }

        return $updated;
    }

    /**
     * @return list<string>
     */
    private function bundledIds(): array
    {
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

        return $ids;
    }

    /**
     * @param list<string> $ids
     */
    private function isBundledRequest(string $path, ?string $routeName, array $ids): bool
    {
        if (is_string($routeName) && preg_match('/^extensions\.([a-z0-9-]+)\./', $routeName, $matches) === 1) {
            return in_array($matches[1], $ids, true);
        }

        $segments = explode('/', $path);
        foreach ($segments as $index => $segment) {
            if ($segment === 'extensions' && isset($segments[$index + 1])) {
                return in_array($segments[$index + 1], $ids, true);
            }
        }

        return false;
    }
}
