<?php

declare(strict_types=1);

namespace Lettr\Laravel\Support;

/**
 * Reads `lettr.templates.*` for the code generators, tolerating the ways an
 * app's config can drift from the package's:
 *
 * - A published `config/lettr.php` whose `templates` array lacks a key — from
 *   an older version, or trimmed by hand. `mergeConfigFrom()` only merges
 *   top-level keys, so the missing key would be null; it falls back to the
 *   package default instead.
 * - A relative path (`app/Dto/Lettr`), resolved against `base_path()` rather
 *   than whatever directory the command happens to run from.
 * - A trailing slash on a path, or a leading/trailing backslash on a namespace.
 */
final class TemplatesConfig
{
    /**
     * @param  'html_path'|'blade_path'|'mailable_path'|'dto_path'|'enum_path'  $key
     */
    public static function path(string $key): string
    {
        return self::absolutePath(self::value($key));
    }

    /**
     * Resolve a path against base_path() unless it is already absolute, without a trailing slash.
     */
    public static function absolutePath(string $path): string
    {
        $path = rtrim($path, '/\\');

        return self::isAbsolute($path) ? $path : base_path($path);
    }

    /**
     * @param  'mailable_namespace'|'dto_namespace'|'enum_namespace'  $key
     */
    public static function namespace(string $key): string
    {
        return trim(self::value($key), '\\');
    }

    public static function enumClass(): string
    {
        return trim(self::value('enum_class'), '\\');
    }

    private static function value(string $key): string
    {
        $value = config("lettr.templates.{$key}");

        if (is_string($value) && $value !== '') {
            return $value;
        }

        /** @var array{templates: array<string, string>} $defaults */
        $defaults = require __DIR__.'/../../config/lettr.php';

        return $defaults['templates'][$key];
    }

    private static function isAbsolute(string $path): bool
    {
        return str_starts_with($path, '/') || preg_match('/^[A-Za-z]:[\\\\\/]/', $path) === 1;
    }
}
