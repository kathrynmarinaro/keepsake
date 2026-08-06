<?php

declare(strict_types=1);

/**
 * Small view/formatting helpers shared across templates. Deliberately
 * global functions (not a class) — these are used throughout plain-PHP
 * view templates where a `use` import per file would be noise.
 */

if (!function_exists('e')) {
    /**
     * HTML-escape a value for safe output. Use on every piece of
     * user-supplied or DB-sourced text rendered into a template.
     */
    function e(?string $value): string
    {
        return htmlspecialchars($value ?? '', ENT_QUOTES, 'UTF-8');
    }
}

if (!function_exists('config')) {
    /**
     * Dot-notation lookup into the loaded app config (see
     * src/bootstrap.php, which stashes the config array here).
     *
     * config('app.base_url')
     */
    function config(string $key, mixed $default = null): mixed
    {
        static $config = null;

        if ($config === null) {
            $config = $GLOBALS['__keepsake_config'] ?? [];
        }

        $value = $config;
        foreach (explode('.', $key) as $segment) {
            if (!is_array($value) || !array_key_exists($segment, $value)) {
                return $default;
            }
            $value = $value[$segment];
        }

        return $value;
    }
}

if (!function_exists('asset')) {
    /**
     * Build a path to a file under public/assets/. Centralized so a future
     * cache-busting query string or CDN prefix is a one-line change.
     */
    function asset(string $path): string
    {
        return '/assets/' . ltrim($path, '/');
    }
}
