<?php

declare(strict_types=1);

namespace Lettr\Laravel\Console;

use Illuminate\Console\Command;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Str;
use Lettr\Dto\Template\ListTemplatesFilter;
use Lettr\Dto\Template\MergeTag;
use Lettr\Dto\Template\Template;
use Lettr\Dto\Template\TemplateDetail;
use Lettr\Laravel\LettrManager;
use Lettr\Laravel\Support\DtoGenerator;
use Lettr\Laravel\Support\SparkpostToBladeConverter;

use function Laravel\Prompts\progress;

class PullCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'lettr:pull
                            {--with-mailables : Also generate Mailable classes for each template}
                            {--dry-run : Preview what would be downloaded without writing files}
                            {--project= : Pull templates from a specific project ID}
                            {--template= : Pull only a specific template by slug}
                            {--as-html : Save as raw HTML instead of converting to Blade}
                            {--skip-templates : Skip downloading templates, only generate DTOs and Mailables}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Pull email templates from Lettr API as Blade files';

    /**
     * @var array<int, array{slug: string, path: string}>
     */
    protected array $downloadedTemplates = [];

    /**
     * @var array<int, array{class: string, path: string}>
     */
    protected array $generatedMailables = [];

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
        protected readonly Filesystem $files,
        protected readonly DtoGenerator $dtoGenerator,
        protected readonly SparkpostToBladeConverter $bladeConverter,
    ) {
        parent::__construct();
    }

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $this->components->info('Pulling templates from Lettr...');

        $projectId = $this->getProjectId();
        /** @var string|null $templateSlug */
        $templateSlug = $this->option('template');
        $dryRun = (bool) $this->option('dry-run');
        $withMailables = (bool) $this->option('with-mailables');
        $asHtml = (bool) $this->option('as-html');
        $skipTemplates = (bool) $this->option('skip-templates');

        // Fetch templates
        $templates = $this->fetchTemplates($projectId, $templateSlug);

        if (empty($templates)) {
            $this->components->warn('No templates found.');

            return self::SUCCESS;
        }

        // Process templates with progress bar
        $this->processTemplates($templates, $projectId, $dryRun, $withMailables, $asHtml, $skipTemplates);

        // Output summary
        $this->outputSummary($dryRun, $withMailables, $skipTemplates);

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
    protected function processTemplates(array $templates, ?int $projectId, bool $dryRun, bool $withMailables, bool $asHtml, bool $skipTemplates): void
    {
        $label = $skipTemplates ? 'Processing templates' : 'Downloading templates';
        $progress = progress(
            label: $label,
            steps: count($templates),
        );

        $progress->start();

        foreach ($templates as $template) {
            $this->processTemplate($template, $projectId, $dryRun, $withMailables, $asHtml, $skipTemplates);
            $progress->advance();
        }

        $progress->finish();
    }

    /**
     * Process a single template.
     */
    protected function processTemplate(Template $template, ?int $projectId, bool $dryRun, bool $withMailables, bool $asHtml, bool $skipTemplates): void
    {
        // Fetch full template details to get the HTML
        $detail = $this->lettr->templates()->get($template->slug, $projectId);

        // Skip templates without HTML (only relevant when downloading)
        if (! $skipTemplates && empty($detail->html)) {
            $this->skippedTemplates[] = $detail->slug;

            return;
        }

        // Save template file (HTML or Blade) unless skipping
        if (! $skipTemplates) {
            if ($asHtml) {
                $templatePath = $this->saveHtml($detail, $dryRun);
            } else {
                $templatePath = $this->saveBlade($detail, $dryRun);
            }

            $this->downloadedTemplates[] = [
                'slug' => $detail->slug,
                'path' => $templatePath,
            ];
        }

        // Generate Mailable and DTO if requested
        if ($withMailables) {
            // Fetch merge tags for the DTO
            $mergeTags = $this->fetchMergeTags($detail, $projectId);

            // Generate DTO if there are merge tags
            if (! empty($mergeTags)) {
                $this->dtoGenerator->generate($detail->slug, $mergeTags, $dryRun);
                foreach ($this->dtoGenerator->getGeneratedDtos() as $dto) {
                    $this->generatedDtos[] = $dto;
                }
            }

            // Generate Mailable with DTO integration
            // Use API template slug mode when skipping templates or --as-html
            $useBlade = ! $skipTemplates && ! $asHtml;
            $mailable = $this->generateMailable($detail, $mergeTags, $dryRun, $useBlade);
            $this->generatedMailables[] = $mailable;
        }
    }

    /**
     * Fetch merge tags for a template.
     *
     * @return array<int, MergeTag>
     */
    protected function fetchMergeTags(TemplateDetail $detail, ?int $projectId): array
    {
        if ($detail->activeVersion === null) {
            return [];
        }

        $response = $this->lettr->templates()->getMergeTags($detail->slug, $projectId, $detail->activeVersion);

        return $response->mergeTags;
    }

    /**
     * Save the template as an HTML file.
     */
    protected function saveHtml(TemplateDetail $template, bool $dryRun): string
    {
        $htmlPath = config('lettr.templates.html_path');
        $filename = $template->slug.'.html';
        $fullPath = $htmlPath.'/'.$filename;
        $relativePath = str_replace(base_path().'/', '', $fullPath);

        if (! $dryRun) {
            $this->ensureDirectoryExists($htmlPath);
            $this->files->put($fullPath, (string) $template->html);
        }

        return $relativePath;
    }

    /**
     * Save the template as a Blade file.
     */
    protected function saveBlade(TemplateDetail $template, bool $dryRun): string
    {
        $bladePath = config('lettr.templates.blade_path');
        $filename = $template->slug.'.blade.php';
        $fullPath = $bladePath.'/'.$filename;
        $relativePath = str_replace(base_path().'/', '', $fullPath);

        if (! $dryRun) {
            $this->ensureDirectoryExists($bladePath);
            $bladeContent = $this->bladeConverter->convert((string) $template->html);
            $this->files->put($fullPath, $bladeContent);
        }

        return $relativePath;
    }

    /**
     * Generate a Mailable class for the template.
     *
     * @param  array<int, MergeTag>  $mergeTags
     * @return array{class: string, path: string}
     */
    protected function generateMailable(TemplateDetail $template, array $mergeTags, bool $dryRun, bool $useBlade = true): array
    {
        $mailablePath = config('lettr.templates.mailable_path');
        $namespace = config('lettr.templates.mailable_namespace');

        $className = $this->slugToClassName($template->slug);
        $filename = $className.'.php';
        $fullPath = $mailablePath.'/'.$filename;
        $relativePath = str_replace(base_path().'/', '', $fullPath);
        $fullyQualifiedClass = $namespace.'\\'.$className;

        if (! $dryRun) {
            $this->ensureDirectoryExists($mailablePath);
            $stub = $useBlade
                ? $this->getBladeMailableStub($namespace, $className, $template, $mergeTags)
                : $this->getMailableStub($namespace, $className, $template, $mergeTags);
            $this->files->put($fullPath, $stub);
        }

        return [
            'class' => $fullyQualifiedClass,
            'path' => $relativePath,
        ];
    }

    /**
     * Get the populated mailable stub content.
     *
     * @param  array<int, MergeTag>  $mergeTags
     */
    protected function getMailableStub(string $namespace, string $className, TemplateDetail $template, array $mergeTags): string
    {
        $stubPath = __DIR__.'/../../stubs/mailable.stub';
        $stub = $this->files->get($stubPath);

        // Convert template name to a readable subject
        $subject = Str::headline($template->name);

        // Generate DTO-related stub content
        $hasMergeTags = ! empty($mergeTags);
        $dtoClassName = $this->dtoGenerator->getDtoClassName($template->slug);
        $dtoFullClass = $this->dtoGenerator->getFullyQualifiedDtoClassName($template->slug);

        $dtoImport = $hasMergeTags ? "use {$dtoFullClass};" : '';
        $dtoProperty = $hasMergeTags ? "public readonly {$dtoClassName} \$data," : '';
        $withMergeTagsMethod = $hasMergeTags ? $this->generateWithMergeTagsMethod() : '';

        // Generate HTML path relative to base path
        $htmlBasePath = config('lettr.templates.html_path');
        $htmlPath = str_replace(base_path().'/', '', $htmlBasePath).'/'.$template->slug.'.html';

        return str_replace(
            [
                '{{ namespace }}',
                '{{ class }}',
                '{{ slug }}',
                '{{ subject }}',
                '{{ htmlPath }}',
                '{{ dtoImport }}',
                '{{ dtoProperty }}',
                '{{ withMergeTagsMethod }}',
            ],
            [
                $namespace,
                $className,
                $template->slug,
                $subject,
                $htmlPath,
                $dtoImport,
                $dtoProperty,
                $withMergeTagsMethod,
            ],
            $stub
        );
    }

    /**
     * Get the populated Blade mailable stub content.
     *
     * @param  array<int, MergeTag>  $mergeTags
     */
    protected function getBladeMailableStub(string $namespace, string $className, TemplateDetail $template, array $mergeTags): string
    {
        $stubPath = __DIR__.'/../../stubs/blade-mailable.stub';
        $stub = $this->files->get($stubPath);

        // Convert template name to a readable subject
        $subject = Str::headline($template->name);

        // Generate DTO-related stub content
        $hasMergeTags = ! empty($mergeTags);
        $dtoClassName = $this->dtoGenerator->getDtoClassName($template->slug);
        $dtoFullClass = $this->dtoGenerator->getFullyQualifiedDtoClassName($template->slug);

        $dtoImport = $hasMergeTags ? "use {$dtoFullClass};" : '';
        $dtoProperty = $hasMergeTags ? "public readonly {$dtoClassName} \$data," : '';
        $withMergeTagsMethod = $hasMergeTags ? $this->generateWithMergeTagsMethod() : '';

        // Generate Blade view path (dot notation for Laravel views)
        $bladeView = 'emails.lettr.'.$template->slug;

        return str_replace(
            [
                '{{ namespace }}',
                '{{ class }}',
                '{{ bladeView }}',
                '{{ subject }}',
                '{{ dtoImport }}',
                '{{ dtoProperty }}',
                '{{ withMergeTagsMethod }}',
            ],
            [
                $namespace,
                $className,
                $bladeView,
                $subject,
                $dtoImport,
                $dtoProperty,
                $withMergeTagsMethod,
            ],
            $stub
        );
    }

    /**
     * Generate the withMergeTags method for the mailable.
     */
    protected function generateWithMergeTagsMethod(): string
    {
        return <<<'PHP'

    /**
     * Get the merge tags for this mailable.
     *
     * @return array<string, mixed>
     */
    public function withMergeTags(): array
    {
        return $this->data->toArray();
    }
PHP;
    }

    /**
     * Convert a slug to a class name.
     */
    protected function slugToClassName(string $slug): string
    {
        return Str::studly($slug);
    }

    /**
     * Ensure a directory exists.
     */
    protected function ensureDirectoryExists(string $path): void
    {
        if (! $this->files->isDirectory($path)) {
            $this->files->makeDirectory($path, 0755, true);
        }
    }

    /**
     * Output the summary of downloaded templates and generated mailables.
     */
    protected function outputSummary(bool $dryRun, bool $withMailables, bool $skipTemplates = false): void
    {
        $this->newLine();

        if (! $skipTemplates && ! empty($this->downloadedTemplates)) {
            $prefix = $dryRun ? 'Would download' : 'Downloaded';
            $this->components->twoColumnDetail("<fg=gray>{$prefix}:</>");

            foreach ($this->downloadedTemplates as $template) {
                $this->components->twoColumnDetail(
                    "  <fg=green>✓</> {$template['slug']}",
                    $template['path']
                );
            }
        }

        if ($withMailables && ! empty($this->generatedDtos)) {
            $this->newLine();
            $dtoPrefix = $dryRun ? 'Would generate DTOs' : 'Generated DTOs';
            $this->components->twoColumnDetail("<fg=gray>{$dtoPrefix}:</>");

            foreach ($this->generatedDtos as $dto) {
                $this->components->twoColumnDetail(
                    "  <fg=green>✓</> {$dto['class']}",
                    $dto['path']
                );
            }
        }

        if ($withMailables && ! empty($this->generatedMailables)) {
            $this->newLine();
            $mailablePrefix = $dryRun ? 'Would generate Mailables' : 'Generated Mailables';
            $this->components->twoColumnDetail("<fg=gray>{$mailablePrefix}:</>");

            foreach ($this->generatedMailables as $mailable) {
                $this->components->twoColumnDetail(
                    "  <fg=green>✓</> {$mailable['class']}",
                    ''
                );
            }
        }

        if (! $skipTemplates && ! empty($this->skippedTemplates)) {
            $this->newLine();
            $this->components->twoColumnDetail('<fg=gray>Skipped (no HTML):</>');

            foreach ($this->skippedTemplates as $slug) {
                $this->components->twoColumnDetail(
                    "  <fg=yellow>⊘</> {$slug}",
                    ''
                );
            }
        }

        $this->newLine();

        if ($skipTemplates) {
            $mailableCount = count($this->generatedMailables);
            $dtoCount = count($this->generatedDtos);
            $action = $dryRun ? 'Would generate' : 'Generated';
            $this->components->info("Done! {$action} {$mailableCount} mailable(s) and {$dtoCount} DTO(s).");
        } else {
            $count = count($this->downloadedTemplates);
            $action = $dryRun ? 'Would download' : 'Downloaded';
            $this->components->info("Done! {$action} {$count} template(s).");
        }
    }
}
