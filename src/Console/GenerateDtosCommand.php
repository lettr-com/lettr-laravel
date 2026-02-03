<?php

declare(strict_types=1);

namespace Lettr\Laravel\Console;

use Illuminate\Console\Command;
use Lettr\Dto\Template\ListTemplatesFilter;
use Lettr\Dto\Template\Template;
use Lettr\Laravel\LettrManager;
use Lettr\Laravel\Support\DtoGenerator;

use function Laravel\Prompts\progress;

class GenerateDtosCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'lettr:generate-dtos
                            {--project= : Generate DTOs for templates from a specific project ID}
                            {--template= : Generate DTO for a specific template by slug}
                            {--dry-run : Preview what would be generated without writing files}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Generate type-safe DTO classes from Lettr template merge tags';

    /**
     * @var array<int, array{class: string, path: string}>
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
        $this->components->info('Generating DTOs from Lettr template merge tags...');

        $projectId = $this->getProjectId();
        /** @var string|null $templateSlug */
        $templateSlug = $this->option('template');
        $dryRun = (bool) $this->option('dry-run');

        // Fetch templates
        $templates = $this->fetchTemplates($projectId, $templateSlug);

        if (empty($templates)) {
            $this->components->warn('No templates found.');

            return self::SUCCESS;
        }

        // Process templates with progress bar
        $this->processTemplates($templates, $projectId, $dryRun);

        // Output summary
        $this->outputSummary($dryRun);

        return self::SUCCESS;
    }

    /**
     * Get the project ID to use for fetching templates.
     */
    protected function getProjectId(): ?int
    {
        $projectOption = $this->option('project');

        if ($projectOption !== null) {
            return (int) $projectOption;
        }

        $configProjectId = config('lettr.default_project_id');

        return is_numeric($configProjectId) ? (int) $configProjectId : null;
    }

    /**
     * Fetch templates from the API.
     *
     * @return array<int, Template>
     */
    protected function fetchTemplates(?int $projectId, ?string $templateSlug): array
    {
        $filter = $projectId !== null
            ? new ListTemplatesFilter(projectId: $projectId, perPage: 100)
            : new ListTemplatesFilter(perPage: 100);

        $response = $this->lettr->templates()->list($filter);
        $templates = $response->templates->all();

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
    protected function processTemplates(array $templates, ?int $projectId, bool $dryRun): void
    {
        $progress = progress(
            label: 'Generating DTOs',
            steps: count($templates),
        );

        $progress->start();

        foreach ($templates as $template) {
            $this->processTemplate($template, $projectId, $dryRun);
            $progress->advance();
        }

        $progress->finish();
    }

    /**
     * Process a single template.
     */
    protected function processTemplate(Template $template, ?int $projectId, bool $dryRun): void
    {
        // Fetch template details to get the active version
        $detail = $this->lettr->templates()->get($template->slug, $projectId);

        // Skip templates without an active version
        if ($detail->activeVersion === null) {
            $this->skippedTemplates[] = $template->slug;

            return;
        }

        // Fetch merge tags for the template using the active version
        $response = $this->lettr->templates()->getMergeTags($template->slug, $projectId, $detail->activeVersion);

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
     * Output the summary of generated DTOs.
     */
    protected function outputSummary(bool $dryRun): void
    {
        $this->newLine();

        $prefix = $dryRun ? 'Would generate' : 'Generated';
        $this->components->twoColumnDetail("<fg=gray>{$prefix}:</>");

        foreach ($this->generatedDtos as $dto) {
            $this->components->twoColumnDetail(
                "  <fg=green>✓</> {$dto['class']}",
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

        $count = count($this->generatedDtos);
        $action = $dryRun ? 'Would generate' : 'Generated';
        $this->components->info("Done! {$action} {$count} DTO class(es).");
    }
}
