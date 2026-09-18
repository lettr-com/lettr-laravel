<?php

declare(strict_types=1);

namespace Lettr\Laravel\Concerns;

/**
 * Generators always rewrite their output, so any local edits to a generated
 * file are lost. Summaries mark files that already existed with ↻ and end
 * with one warning, instead of refusing to write.
 */
trait WarnsAboutOverwrites
{
    /**
     * The summary marker for a written file.
     */
    protected function writeMarker(bool $overwritten): string
    {
        return $overwritten ? '<fg=yellow>↻</>' : '<fg=green>✓</>';
    }

    /**
     * Warn when existing files were (or, in a dry run, would be) overwritten.
     */
    protected function warnAboutOverwrites(int $count, bool $dryRun): void
    {
        if ($count === 0) {
            return;
        }

        $this->components->warn($dryRun
            ? "{$count} existing file(s) would be overwritten (marked ↻)."
            : "{$count} existing file(s) were overwritten (marked ↻). Any local changes to them are gone; check your version control if that wasn't intended.");
    }
}
