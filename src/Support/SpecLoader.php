<?php

declare(strict_types=1);

namespace Cloudstudio\LaravelCodemode\Support;

use Dedoc\Scramble\Generator;
use Dedoc\Scramble\Scramble;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Load and cache the OpenAPI spec from multiple sources.
 *
 * Supports loading from Scramble (auto-generated), a remote URL,
 * a local JSON file, or auto-detection that tries all sources in
 * sequence. The loaded spec is pre-processed (refs resolved, methods
 * filtered) and cached to disk for subsequent requests.
 */
class SpecLoader
{
    /** @var list<string> Standard HTTP methods recognized in OpenAPI paths. */
    public const HTTP_METHODS = ['get', 'post', 'put', 'patch', 'delete', 'head', 'options', 'trace'];

    /**
     * Load the OpenAPI spec with ref resolution, method filtering, and caching.
     *
     * @return array|null The processed spec, or null if no source available.
     */
    public static function load(): ?array
    {
        $cached = static::loadFromCache();
        if ($cached !== null) {
            return $cached;
        }

        $spec = static::loadFromSource();
        if ($spec === null) {
            return null;
        }

        $spec = RefResolver::resolve($spec);
        $spec = static::filterExcludedMethods($spec);

        static::saveToCache($spec);

        return $spec;
    }

    /**
     * Try loading the spec from the disk cache.
     *
     * @return array|null The cached spec array, or null if caching is disabled or cache miss.
     */
    private static function loadFromCache(): ?array
    {
        if (! config('codemode.spec.cache', true)) {
            return null;
        }

        $path = config('codemode.spec.cache_path', storage_path('app/openapi-spec.json'));

        if (! file_exists($path)) {
            return null;
        }

        $spec = json_decode(file_get_contents($path), true);

        return is_array($spec) ? $spec : null;
    }

    /**
     * Save the processed spec to disk cache.
     *
     * @param  array  $spec  The fully resolved and filtered spec to persist.
     * @return void
     */
    private static function saveToCache(array $spec): void
    {
        if (! config('codemode.spec.cache', true)) {
            return;
        }

        $path = config('codemode.spec.cache_path', storage_path('app/openapi-spec.json'));
        $dir = dirname($path);

        if (! is_dir($dir)) {
            mkdir($dir, 0755, true);
        }

        file_put_contents($path, json_encode($spec, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
    }

    /**
     * Load the spec from the configured source (scramble, url, file, or auto).
     *
     * @return array|null The raw spec array, or null if the source is unavailable.
     */
    private static function loadFromSource(): ?array
    {
        return match (config('codemode.spec.source', 'auto')) {
            'scramble' => static::fromScramble(),
            'url' => static::fromUrl(config('codemode.spec.url')),
            'file' => static::fromFile(config('codemode.spec.path')),
            default => static::auto(),
        };
    }

    /**
     * Auto-detect: try Scramble, configured URL, configured file, then fallback.
     *
     * @return array|null The spec from the first successful source, or null if all fail.
     */
    private static function auto(): ?array
    {
        if ($spec = static::fromScramble()) {
            return $spec;
        }
        Log::debug('[CodeMode] SpecLoader: Scramble not available, trying next source.');

        $url = config('codemode.spec.url');
        if ($url) {
            if ($spec = static::fromUrl($url)) {
                return $spec;
            }
            Log::warning("[CodeMode] SpecLoader: Failed to load spec from URL: {$url}");
        }

        $path = config('codemode.spec.path');
        if ($path) {
            if ($spec = static::fromFile($path)) {
                return $spec;
            }
            Log::warning("[CodeMode] SpecLoader: Failed to load spec from file: {$path}");
        }

        $fallbackUrl = config('codemode.api.base_url', config('app.url', 'http://localhost:8000')).'/docs/api.json';
        $spec = static::fromUrl($fallbackUrl);

        if ($spec === null) {
            Log::error('[CodeMode] SpecLoader: All spec sources failed. Configure codemode.spec.source, .url, or .path.');
        }

        return $spec;
    }

    /**
     * Generate the spec from Scramble.
     *
     * @return array|null The generated spec array, or null if Scramble is unavailable.
     */
    private static function fromScramble(): ?array
    {
        try {
            $config = Scramble::getGeneratorConfig('default');

            return app(Generator::class)($config);
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * Fetch the spec from a remote URL.
     *
     * @param  string|null  $url  The URL to fetch the spec from.
     * @return array|null The decoded spec array, or null on failure or empty URL.
     */
    private static function fromUrl(?string $url): ?array
    {
        if (empty($url)) {
            return null;
        }

        try {
            $response = Http::timeout(10)->get($url);

            return $response->successful() ? $response->json() : null;
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * Read the spec from a local JSON file.
     *
     * Relative paths are resolved against the application base path.
     *
     * @param  string|null  $path  Absolute or relative path to the JSON spec file.
     * @return array|null The decoded spec array, or null on failure or empty path.
     */
    private static function fromFile(?string $path): ?array
    {
        if (empty($path)) {
            return null;
        }

        $path = str_starts_with($path, '/') ? $path : base_path($path);

        if (! file_exists($path)) {
            return null;
        }

        $spec = json_decode(file_get_contents($path), true);

        return is_array($spec) ? $spec : null;
    }

    /**
     * Remove operations whose HTTP method is in the exclude list.
     *
     * If a path has no methods left after filtering, the path is removed entirely.
     *
     * @param  array  $spec  The full OpenAPI spec array.
     * @return array The spec with excluded HTTP methods removed from paths.
     */
    private static function filterExcludedMethods(array $spec): array
    {
        $excluded = static::parseExcludedMethods();

        if (empty($excluded) || ! isset($spec['paths'])) {
            return $spec;
        }

        foreach ($spec['paths'] as $path => $operations) {
            $spec['paths'][$path] = array_diff_key($operations, array_flip($excluded));

            if (! static::hasHttpOperations($spec['paths'][$path])) {
                unset($spec['paths'][$path]);
            }
        }

        return $spec;
    }

    /**
     * Parse the comma-separated exclude_methods config into an array.
     *
     * @return list<string>
     */
    private static function parseExcludedMethods(): array
    {
        $raw = config('codemode.exclude_methods', '');

        if (empty($raw)) {
            return [];
        }

        return array_values(array_filter(
            array_map(fn (string $m): string => strtolower(trim($m)), explode(',', $raw)),
        ));
    }

    /**
     * Check if a path entry has at least one valid HTTP operation.
     *
     * @param  array  $operations  The path item object (keyed by HTTP method or extension).
     * @return bool True if at least one standard HTTP method key exists.
     */
    private static function hasHttpOperations(array $operations): bool
    {
        foreach (self::HTTP_METHODS as $method) {
            if (isset($operations[$method])) {
                return true;
            }
        }

        return false;
    }
}
