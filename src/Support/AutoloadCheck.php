<?php

declare(strict_types=1);

namespace Lettr\Laravel\Support;

use Composer\Autoload\ClassLoader;

/**
 * Checks that a generated class will autoload from where it was written.
 *
 * A namespace config that doesn't match the app's PSR-4 mapping (say
 * `dto_namespace` `App\Data\Lettr` with `dto_path` `app/Dto/Lettr`) still
 * generates valid files — they just never load, which only shows up the first
 * time the app uses one.
 */
final class AutoloadCheck
{
    /**
     * A warning when Composer would not load $class from $file, or null.
     */
    public static function warning(string $class, string $file): ?string
    {
        $expected = realpath($file);

        if ($expected === false) {
            return null;
        }

        foreach (ClassLoader::getRegisteredLoaders() as $loader) {
            // An authoritative class map only knows classes from the last dump-autoload.
            if ($loader->isClassMapAuthoritative()) {
                return null;
            }

            $found = $loader->findFile($class);

            if ($found !== false && realpath($found) === $expected) {
                return null;
            }
        }

        return "{$class} won't autoload from {$file}. Check that the namespace and path in config/lettr.php match the PSR-4 autoload section of composer.json.";
    }
}
