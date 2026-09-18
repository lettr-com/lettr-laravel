<?php

declare(strict_types=1);

namespace Lettr\Laravel\Support;

use Illuminate\Support\Str;

/**
 * Turns Lettr slugs and merge tag keys into names PHP will accept.
 *
 * Slugs are `Str::slug()` output (`[a-z0-9-]`), so a template named "2FA code"
 * has the slug `2fa-code` and one named "New" has `new` — neither studly-cases
 * into a valid class name. Merge tag keys match `[A-Za-z_][A-Za-z0-9_]*`.
 */
final class PhpIdentifier
{
    /**
     * Prefix for names that would otherwise start with a digit, and suffix for
     * names PHP reserves.
     */
    private const DISAMBIGUATOR = 'Template';

    /**
     * Words PHP 8.4 rejects as a class name, compared case-insensitively.
     */
    private const RESERVED_CLASS_NAMES = [
        'abstract', 'and', 'array', 'as', 'bool', 'break', 'callable', 'case', 'catch', 'class',
        'clone', 'const', 'continue', 'declare', 'default', 'die', 'do', 'echo', 'else', 'elseif',
        'empty', 'enddeclare', 'endfor', 'endforeach', 'endif', 'endswitch', 'endwhile', 'eval',
        'exit', 'extends', 'false', 'final', 'finally', 'float', 'fn', 'for', 'foreach', 'function',
        'global', 'goto', 'if', 'implements', 'include', 'include_once', 'instanceof', 'insteadof',
        'int', 'interface', 'isset', 'iterable', 'list', 'match', 'mixed', 'namespace', 'never',
        'new', 'null', 'object', 'or', 'parent', 'print', 'private', 'protected', 'public',
        'readonly', 'require', 'require_once', 'return', 'self', 'static', 'string', 'switch',
        'throw', 'trait', 'true', 'try', 'unset', 'use', 'var', 'void', 'while', 'xor', 'yield',
    ];

    /**
     * A class name for a template slug: `welcome-email` → `WelcomeEmail`,
     * `2fa-code` → `Template2faCode`, `new` → `NewTemplate`.
     */
    public static function className(string $slug): string
    {
        $name = self::withoutLeadingDigit(Str::studly($slug));

        if (in_array(strtolower($name), self::RESERVED_CLASS_NAMES, true)) {
            return $name.self::DISAMBIGUATOR;
        }

        return $name;
    }

    /**
     * An enum case name for a template slug. Enum cases only reserve `class`,
     * so `new` stays `New` here while its Mailable is `NewTemplate`.
     */
    public static function enumCase(string $slug): string
    {
        $name = self::withoutLeadingDigit(Str::studly($slug));

        if (strtolower($name) === 'class') {
            return $name.self::DISAMBIGUATOR;
        }

        return $name;
    }

    /**
     * A camelCase property name for a merge tag key. Keys that are entirely
     * upper case are lowered first, so `FIRST_NAME` becomes `firstName` rather
     * than `fIRSTNAME`; any other key keeps its casing (`orderId` → `orderId`).
     */
    public static function property(string $key): string
    {
        $name = Str::camel(self::isUpperCase($key) ? Str::lower($key) : $key);

        return $name === 'this' ? 'thisValue' : $name;
    }

    /**
     * A StudlyCase fragment for a merge tag key, with the same casing rule as
     * {@see property()}.
     */
    public static function studlyKey(string $key): string
    {
        return Str::studly(self::isUpperCase($key) ? Str::lower($key) : $key);
    }

    private static function withoutLeadingDigit(string $name): string
    {
        return ctype_digit(substr($name, 0, 1)) ? self::DISAMBIGUATOR.$name : $name;
    }

    private static function isUpperCase(string $key): bool
    {
        return $key === strtoupper($key);
    }
}
