<?php

declare(strict_types=1);

namespace Lettr\Laravel\Console;

use Illuminate\Console\Command;
use Lettr\Dto\Template\Template;
use Lettr\Laravel\Concerns\FetchesAllTemplates;
use Lettr\Laravel\Concerns\WarnsAboutOverwrites;
use Lettr\Laravel\LettrManager;
use Lettr\Laravel\Support\AutoloadCheck;
use Lettr\Laravel\Support\DtoGenerator;
use Lettr\Laravel\Support\TemplatesConfig;

use function Laravel\Prompts\progress;

class GenerateDtosCommand extends Command
{
    use FetchesAllTemplates;
    use WarnsAboutOverwrites;

    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'lettr:generate-dtos
                            {--template= : Generate DTO for a specific template by slug}
                            {--dry-run : Preview what would be generated without writing files}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Generate type-safe DTO classes from Lettr template merge tags';

    /**
     * @var array<int, array{class: string, path: string, overwritten: bool}>
     */
    protected array $generatedDtos = [];

    /**
     * @var array<int, string>
     */
    protected array $skippedTemplates = [];

    public function __construct(
        protected readonly LettrManager $lettr,
        protected readonly DtoGenerator $dtoGenerator,
    ) {
        parent::__construct();
    }

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        // Artisan reuses the command instance within a process, so start each run clean
        $this->generatedDtos = $this->skippedTemplates = [];

        $this->components->info('Generating DTOs from Lettr template merge tags...');

        /** @var string|null $templateSlug */
        $templateSlug = $this->option('template');
        $dryRun = (bool) $this->option('dry-run');

        // Fetch templates
        $templates = $this->fetchTemplates($templateSlug);

        if (empty($templates)) {
            if ($templateSlug !== null) {
                return self::FAILURE;
            }

            $this->components->warn('No templates found.');

            return self::SUCCESS;
        }

        // Process templates with progress bar
        $this->processTemplates($templates, $dryRun);

        if (! $dryRun && isset($this->generatedDtos[0])) {
            $this->warnIfNotAutoloadable($this->generatedDtos[0]);
        }

        // Output summary
        $this->outputSummary($dryRun);

        return self::SUCCESS;
    }

    /**
     * Fetch templates from the API.
     *
     * @return array<int, Template>
     */
    protected function fetchTemplates(?string $templateSlug): array
    {
        $templates = $this->fetchAllTemplates();

        // Filter by slug if specified
        if ($templateSlug !== null) {
            $templates = array_filter(
                $templates,
                fn (Template $t): bool => $t->slug === $templateSlug
            );

            if (empty($templates)) {
                $this->components->error("Template with slug '{$templateSlug}' not found.");
            }
        }

        return array_values($templates);
    }

    /**
     * Process all templates.
     *
     * @param  array<int, Template>  $templates
     */
    protected function processTemplates(array $templates, bool $dryRun): void
    {
        $progress = progress(
            label: 'Generating DTOs',
            steps: count($templates),
        );

        $progress->start();

        foreach ($templates as $template) {
            $this->processTemplate($template, $dryRun);
            $progress->advance();
        }

        $progress->finish();
    }

    /**
     * Process a single template.
     */
    protected function processTemplate(Template $template, bool $dryRun): void
    {
        // Fetch template details to get the active version
        $detail = $this->withRateLimitRetry(fn () => $this->lettr->templates()->get($template->slug));

        // Skip templates without an active version
        if ($detail->activeVersion === null) {
            $this->skippedTemplates[] = $template->slug;

            return;
        }

        // Fetch merge tags for the template using the active version
        $response = $this->withRateLimitRetry(fn () => $this->lettr->templates()->getMergeTags($template->slug, null, $detail->activeVersion));

        // Skip templates without merge tags
        if (empty($response->mergeTags)) {
            $this->skippedTemplates[] = $template->slug;

            return;
        }

        // Generate DTO classes using the shared generator
        $this->dtoGenerator->generate($template->slug, $response->mergeTags, $dryRun);

        foreach ($this->dtoGenerator->getGeneratedDtos() as $dto) {
            $this->generatedDtos[] = $dto;
        }
    }

    /**
     * Warn when a generated class won't load under the app's PSR-4 mapping.
     *
     * @param  array{class: string, path: string}  $generated
     */
    protected function warnIfNotAutoloadable(array $generated): void
    {
        $file = TemplatesConfig::path('dto_path').'/'.class_basename($generated['class']).'.php';

        if (($warning = AutoloadCheck::warning($generated['class'], $file)) !== null) {
            $this->components->warn($warning);
        }
    }

    /**
     * Output the summary of generated DTOs.
     */
    protected function outputSummary(bool $dryRun): void
    {
        $this->newLine();

        $prefix = $dryRun ? 'Would generate' : 'Generated';
        $this->components->twoColumnDetail("<fg=gray>{$prefix}:</>");

        foreach ($this->generatedDtos as $dto) {
            $this->components->twoColumnDetail(
                "  {$this->writeMarker($dto['overwritten'])} {$dto['class']}",
                $dto['path']
            );
        }

        if (! empty($this->skippedTemplates)) {
            $this->newLine();
            $this->components->twoColumnDetail('<fg=gray>Skipped (no merge tags):</>');

            foreach ($this->skippedTemplates as $slug) {
                $this->components->twoColumnDetail(
                    "  <fg=yellow>⊘</> {$slug}",
                    ''
                );
            }
        }

        $this->newLine();

        $this->warnAboutOverwrites(count(array_filter($this->generatedDtos, fn (array $dto): bool => $dto['overwritten'])), $dryRun);

        $count = count($this->generatedDtos);
        $action = $dryRun ? 'Would generate' : 'Generated';
        $this->components->info("Done! {$action} {$count} DTO class(es).");
    }
}
