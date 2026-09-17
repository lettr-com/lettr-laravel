<?php

declare(strict_types=1);

namespace Lettr\Laravel\Console;

use Illuminate\Console\Command;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Str;
use Illuminate\View\FileViewFinder;
use Lettr\Dto\Template\MergeTag;
use Lettr\Dto\Template\Template;
use Lettr\Dto\Template\TemplateDetail;
use Lettr\Laravel\Concerns\FetchesAllTemplates;
use Lettr\Laravel\Concerns\WarnsAboutOverwrites;
use Lettr\Laravel\LettrManager;
use Lettr\Laravel\Support\AutoloadCheck;
use Lettr\Laravel\Support\DtoGenerator;
use Lettr\Laravel\Support\PhpIdentifier;
use Lettr\Laravel\Support\SparkpostToBladeConverter;
use Lettr\Laravel\Support\TemplatesConfig;

use function Laravel\Prompts\progress;

class PullCommand extends Command
{
    use FetchesAllTemplates;
    use WarnsAboutOverwrites;

    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'lettr:pull
                            {--with-mailables : Also generate Mailable classes for each template}
                            {--dry-run : Preview what would be downloaded without writing files}
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
     * @var array<int, array{slug: string, path: string, overwritten: bool}>
     */
    protected array $downloadedTemplates = [];

    /**
     * @var array<int, array{class: string, path: string, overwritten: bool}>
     */
    protected array $generatedMailables = [];

    /**
     * @var array<int, array{class: string, path: string, overwritten: bool}>
     */
    protected array $generatedDtos = [];

    /**
     * @var array<int, string>
     */
    protected array $skippedTemplates = [];

    /**
     * Whether the blade_path warning has been shown this run.
     */
    protected bool $warnedAboutBladePath = false;

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
        // Artisan reuses the command instance within a process, so start each run clean
        $this->downloadedTemplates = $this->generatedMailables = $this->generatedDtos = $this->skippedTemplates = [];
        $this->warnedAboutBladePath = false;

        $this->components->info('Pulling templates from Lettr...');

        /** @var string|null $templateSlug */
        $templateSlug = $this->option('template');
        $dryRun = (bool) $this->option('dry-run');
        $withMailables = (bool) $this->option('with-mailables');
        $asHtml = (bool) $this->option('as-html');
        $skipTemplates = (bool) $this->option('skip-templates');

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
        $this->processTemplates($templates, $dryRun, $withMailables, $asHtml, $skipTemplates);

        if (! $dryRun) {
            if (isset($this->generatedDtos[0])) {
                $this->warnIfNotAutoloadable($this->generatedDtos[0]['class'], TemplatesConfig::path('dto_path'));
            }

            if (isset($this->generatedMailables[0])) {
                $this->warnIfNotAutoloadable($this->generatedMailables[0]['class'], TemplatesConfig::path('mailable_path'));
            }
        }

        // Output summary
        $this->outputSummary($dryRun, $withMailables, $skipTemplates);

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
    protected function processTemplates(array $templates, bool $dryRun, bool $withMailables, bool $asHtml, bool $skipTemplates): void
    {
        $label = $skipTemplates ? 'Processing templates' : 'Downloading templates';
        $progress = progress(
            label: $label,
            steps: count($templates),
        );

        $progress->start();

        foreach ($templates as $template) {
            $this->processTemplate($template, $dryRun, $withMailables, $asHtml, $skipTemplates);
            $progress->advance();
        }

        $progress->finish();
    }

    /**
     * Process a single template.
     */
    protected function processTemplate(Template $template, bool $dryRun, bool $withMailables, bool $asHtml, bool $skipTemplates): void
    {
        // Fetch full template details to get the HTML
        $detail = $this->withRateLimitRetry(fn () => $this->lettr->templates()->get($template->slug));

        // Skip templates without HTML (only relevant when downloading)
        if (! $skipTemplates && empty($detail->html)) {
            $this->skippedTemplates[] = $detail->slug;

            return;
        }

        // Save template file (HTML or Blade) unless skipping
        if (! $skipTemplates) {
            $this->downloadedTemplates[] = [
                'slug' => $detail->slug,
                ...($asHtml ? $this->saveHtml($detail, $dryRun) : $this->saveBlade($detail, $dryRun)),
            ];
        }

        // Generate Mailable and DTO if requested
        if ($withMailables) {
            // Fetch merge tags for the DTO
            $mergeTags = $this->fetchMergeTags($detail);

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
            $this->generatedMailables[] = $this->generateMailable($detail, $mergeTags, $dryRun, $useBlade);
        }
    }

    /**
     * Fetch merge tags for a template.
     *
     * @return array<int, MergeTag>
     */
    protected function fetchMergeTags(TemplateDetail $detail): array
    {
        if ($detail->activeVersion === null) {
            return [];
        }

        $response = $this->withRateLimitRetry(fn () => $this->lettr->templates()->getMergeTags($detail->slug, null, $detail->activeVersion));

        return $response->mergeTags;
    }

    /**
     * Save the template as an HTML file.
     *
     * @return array{path: string, overwritten: bool}
     */
    protected function saveHtml(TemplateDetail $template, bool $dryRun): array
    {
        $htmlPath = TemplatesConfig::path('html_path');
        $filename = $template->slug.'.html';
        $fullPath = $htmlPath.'/'.$filename;
        $relativePath = str_replace(base_path().'/', '', $fullPath);
        $overwritten = $this->files->exists($fullPath);

        if (! $dryRun) {
            $this->ensureDirectoryExists($htmlPath);
            $this->files->put($fullPath, (string) $template->html);
        }

        return ['path' => $relativePath, 'overwritten' => $overwritten];
    }

    /**
     * Save the template as a Blade file.
     *
     * @return array{path: string, overwritten: bool}
     */
    protected function saveBlade(TemplateDetail $template, bool $dryRun): array
    {
        $bladePath = TemplatesConfig::path('blade_path');
        $filename = $template->slug.'.blade.php';
        $fullPath = $bladePath.'/'.$filename;
        $relativePath = str_replace(base_path().'/', '', $fullPath);
        $overwritten = $this->files->exists($fullPath);

        if (! $dryRun) {
            $this->ensureDirectoryExists($bladePath);
            $bladeContent = $this->bladeConverter->convert((string) $template->html);
            $this->files->put($fullPath, $bladeContent);
        }

        return ['path' => $relativePath, 'overwritten' => $overwritten];
    }

    /**
     * Generate a Mailable class for the template.
     *
     * @param  array<int, MergeTag>  $mergeTags
     * @return array{class: string, path: string, overwritten: bool}
     */
    protected function generateMailable(TemplateDetail $template, array $mergeTags, bool $dryRun, bool $useBlade = true): array
    {
        $mailablePath = TemplatesConfig::path('mailable_path');
        $namespace = TemplatesConfig::namespace('mailable_namespace');

        $className = $this->slugToClassName($template->slug);
        $filename = $className.'.php';
        $fullPath = $mailablePath.'/'.$filename;
        $relativePath = str_replace(base_path().'/', '', $fullPath);
        $fullyQualifiedClass = $namespace.'\\'.$className;

        $overwritten = $this->files->exists($fullPath);

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
            'overwritten' => $overwritten,
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

        // No subject: the template's own subject in Lettr is used unless the request sets one

        // Generate DTO-related stub content
        $hasMergeTags = ! empty($mergeTags);
        $dtoClassName = $this->dtoGenerator->getDtoClassName($template->slug);
        $dtoFullClass = $this->dtoGenerator->getFullyQualifiedDtoClassName($template->slug);

        $dtoImport = $hasMergeTags ? "use {$dtoFullClass};" : '';
        $dtoProperty = $hasMergeTags ? "public readonly {$dtoClassName} \$data," : '';
        $withMergeTagsMethod = $hasMergeTags ? $this->generateWithMergeTagsMethod() : '';

        // Generate HTML path relative to base path
        $htmlBasePath = TemplatesConfig::path('html_path');
        $htmlPath = str_replace(base_path().'/', '', $htmlBasePath).'/'.$template->slug.'.html';

        return str_replace(
            [
                '{{ namespace }}',
                '{{ class }}',
                '{{ slug }}',
                '{{ htmlPath }}',
                '{{ dtoImport }}',
                '{{ dtoProperty }}',
                '{{ withMergeTagsMethod }}',
            ],
            [
                $namespace,
                $className,
                var_export($template->slug, true),
                var_export($htmlPath, true),
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
        $bladeView = $this->bladeViewName($template->slug);

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
                var_export($bladeView, true),
                var_export($subject, true),
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
     * Get the dot-notation view name of a pulled Blade template.
     *
     * The name is derived from lettr.templates.blade_path relative to the view
     * paths, so a custom blade_path still resolves.
     */
    protected function bladeViewName(string $slug): string
    {
        $bladePath = TemplatesConfig::path('blade_path');

        $finder = app('view')->getFinder();
        $viewPaths = $finder instanceof FileViewFinder ? $finder->getPaths() : [resource_path('views')];

        foreach ($viewPaths as $viewPath) {
            $viewPath = rtrim($viewPath, '/');

            if ($bladePath === $viewPath) {
                return $slug;
            }

            if (str_starts_with($bladePath, $viewPath.'/')) {
                return str_replace('/', '.', substr($bladePath, strlen($viewPath) + 1)).'.'.$slug;
            }
        }

        if (! $this->warnedAboutBladePath) {
            $this->warnedAboutBladePath = true;
            $this->components->warn("lettr.templates.blade_path ({$bladePath}) is not inside a view path, so the generated Mailables cannot find their views.");
        }

        return 'emails.lettr.'.$slug;
    }

    /**
     * Warn when a generated class won't load under the app's PSR-4 mapping.
     */
    protected function warnIfNotAutoloadable(string $class, string $directory): void
    {
        if (($warning = AutoloadCheck::warning($class, $directory.'/'.class_basename($class).'.php')) !== null) {
            $this->components->warn($warning);
        }
    }

    /**
     * Convert a slug to a class name.
     */
    protected function slugToClassName(string $slug): string
    {
        return PhpIdentifier::className($slug);
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
                    "  {$this->writeMarker($template['overwritten'])} {$template['slug']}",
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
                    "  {$this->writeMarker($dto['overwritten'])} {$dto['class']}",
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
                    "  {$this->writeMarker($mailable['overwritten'])} {$mailable['class']}",
                    $mailable['path']
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

        $this->warnAboutOverwrites(
            count(array_filter([...$this->downloadedTemplates, ...$this->generatedDtos, ...$this->generatedMailables], fn (array $file): bool => $file['overwritten'])),
            $dryRun,
        );

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
