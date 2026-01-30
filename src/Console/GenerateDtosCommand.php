<?php

declare(strict_types=1);

namespace Lettr\Laravel\Console;

use Illuminate\Console\Command;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Str;
use Lettr\Dto\Template\ListTemplatesFilter;
use Lettr\Dto\Template\MergeTag;
use Lettr\Dto\Template\MergeTagChild;
use Lettr\Dto\Template\Template;
use Lettr\Laravel\LettrManager;

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
        protected readonly Filesystem $files,
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

        // Generate DTO classes (main + nested)
        $baseClassName = $this->slugToClassName($template->slug).'Data';
        $this->generateDto($baseClassName, $response->mergeTags, $dryRun);
    }

    /**
     * Generate a DTO class for the given merge tags.
     *
     * @param  array<int, MergeTag>  $mergeTags
     */
    protected function generateDto(string $className, array $mergeTags, bool $dryRun): void
    {
        $dtoPath = config('lettr.templates.dto_path');
        $namespace = config('lettr.templates.dto_namespace');

        $fullPath = $dtoPath.'/'.$className.'.php';
        $relativePath = str_replace(base_path().'/', '', $fullPath);
        $fullyQualifiedClass = $namespace.'\\'.$className;

        // Generate nested DTOs first (for array loops)
        $nestedClasses = [];
        foreach ($mergeTags as $mergeTag) {
            if ($this->hasNestedChildren($mergeTag)) {
                $nestedClassName = $this->getNestedClassName($className, $mergeTag->key);
                $nestedClasses[$mergeTag->key] = $nestedClassName;
                $this->generateNestedDto($nestedClassName, $mergeTag->children ?? [], $dryRun);
            }
        }

        // Generate the main DTO
        $properties = $this->generateProperties($mergeTags, $className);
        $toArrayBody = $this->generateToArrayBody($mergeTags, $className);
        $docblock = $this->generateDocblock($mergeTags, $className);

        $stub = $this->getStubContent();
        $content = str_replace(
            ['{{ namespace }}', '{{ imports }}', '{{ class }}', '{{ docblock }}', '{{ properties }}', '{{ toArrayBody }}'],
            [$namespace, '', $className, $docblock, $properties, $toArrayBody],
            $stub
        );

        if (! $dryRun) {
            $this->ensureDirectoryExists($dtoPath);
            $this->files->put($fullPath, $content);
        }

        $this->generatedDtos[] = [
            'class' => $fullyQualifiedClass,
            'path' => $relativePath,
        ];
    }

    /**
     * Generate a nested DTO class for child merge tags.
     *
     * @param  array<int, MergeTagChild>  $children
     */
    protected function generateNestedDto(string $className, array $children, bool $dryRun): void
    {
        $dtoPath = config('lettr.templates.dto_path');
        $namespace = config('lettr.templates.dto_namespace');

        $fullPath = $dtoPath.'/'.$className.'.php';
        $relativePath = str_replace(base_path().'/', '', $fullPath);
        $fullyQualifiedClass = $namespace.'\\'.$className;

        $properties = $this->generateChildProperties($children);
        $toArrayBody = $this->generateChildToArrayBody($children);

        $stub = $this->getStubContent();
        $content = str_replace(
            ['{{ namespace }}', '{{ imports }}', '{{ class }}', '{{ docblock }}', '{{ properties }}', '{{ toArrayBody }}'],
            [$namespace, '', $className, '', $properties, $toArrayBody],
            $stub
        );

        if (! $dryRun) {
            $this->ensureDirectoryExists($dtoPath);
            $this->files->put($fullPath, $content);
        }

        $this->generatedDtos[] = [
            'class' => $fullyQualifiedClass,
            'path' => $relativePath,
        ];
    }

    /**
     * Generate PHPDoc block for constructor with array type hints.
     *
     * @param  array<int, MergeTag>  $mergeTags
     */
    protected function generateDocblock(array $mergeTags, string $parentClassName): string
    {
        $params = [];

        foreach ($mergeTags as $tag) {
            if ($this->hasNestedChildren($tag)) {
                $nestedClassName = $this->getNestedClassName($parentClassName, $tag->key);
                $propertyName = $this->keyToPropertyName($tag->key);
                $params[] = "     * @param {$nestedClassName}[]|null \${$propertyName}";
            }
        }

        if (empty($params)) {
            return '';
        }

        return "    /**\n".implode("\n", $params)."\n     */\n";
    }

    /**
     * Check if a merge tag has nested children (for loops).
     */
    protected function hasNestedChildren(MergeTag $mergeTag): bool
    {
        return ! empty($mergeTag->children);
    }

    /**
     * Generate constructor properties for merge tags.
     *
     * @param  array<int, MergeTag>  $mergeTags
     */
    protected function generateProperties(array $mergeTags, string $parentClassName): string
    {
        $lines = [];

        // Sort: required properties first, then optional
        $required = array_filter($mergeTags, fn (MergeTag $tag): bool => $tag->required);
        $optional = array_filter($mergeTags, fn (MergeTag $tag): bool => ! $tag->required);

        foreach ($required as $tag) {
            $lines[] = $this->generatePropertyLine($tag, $parentClassName, true);
        }

        foreach ($optional as $tag) {
            $lines[] = $this->generatePropertyLine($tag, $parentClassName, false);
        }

        return implode("\n", $lines);
    }

    /**
     * Generate a single property line.
     */
    protected function generatePropertyLine(MergeTag $tag, string $parentClassName, bool $isRequired): string
    {
        $propertyName = $this->keyToPropertyName($tag->key);
        $phpType = $this->mapTypeToPhp($tag, $parentClassName);

        if ($isRequired) {
            return "        public {$phpType} \${$propertyName},";
        }

        return "        public ?{$phpType} \${$propertyName} = null,";
    }

    /**
     * Generate constructor properties for child merge tags.
     *
     * @param  array<int, MergeTagChild>  $children
     */
    protected function generateChildProperties(array $children): string
    {
        $lines = [];

        // Children don't have required flag, so all are optional
        foreach ($children as $child) {
            $propertyName = $this->keyToPropertyName($child->key);
            $phpType = $this->mapChildTypeToPhp($child);
            $lines[] = "        public ?{$phpType} \${$propertyName} = null,";
        }

        return implode("\n", $lines);
    }

    /**
     * Generate the toArray body for merge tags.
     *
     * @param  array<int, MergeTag>  $mergeTags
     */
    protected function generateToArrayBody(array $mergeTags, string $parentClassName): string
    {
        $lines = [];

        foreach ($mergeTags as $tag) {
            $propertyName = $this->keyToPropertyName($tag->key);
            $key = $tag->key;

            if ($this->hasNestedChildren($tag)) {
                // Array of nested DTOs - map each item to array with typed closure
                $nestedClassName = $this->getNestedClassName($parentClassName, $tag->key);
                $lines[] = "            '{$key}' => array_map(fn ({$nestedClassName} \$item) => \$item->toArray(), \$this->{$propertyName}),";
            } else {
                $lines[] = "            '{$key}' => \$this->{$propertyName},";
            }
        }

        return implode("\n", $lines);
    }

    /**
     * Generate the toArray body for child merge tags.
     *
     * @param  array<int, MergeTagChild>  $children
     */
    protected function generateChildToArrayBody(array $children): string
    {
        $lines = [];

        foreach ($children as $child) {
            $propertyName = $this->keyToPropertyName($child->key);
            $key = $child->key;
            $lines[] = "            '{$key}' => \$this->{$propertyName},";
        }

        return implode("\n", $lines);
    }

    /**
     * Map a merge tag type to a PHP type.
     */
    protected function mapTypeToPhp(MergeTag $tag, string $parentClassName): string
    {
        // Children means it's a loop - type is array (of nested DTOs)
        if ($this->hasNestedChildren($tag)) {
            return 'array';
        }

        return match ($tag->type) {
            'string', 'text' => 'string',
            'integer', 'int' => 'int',
            'number', 'float' => 'float',
            'boolean', 'bool' => 'bool',
            'array' => 'array',
            default => 'string',
        };
    }

    /**
     * Map a child merge tag type to a PHP type.
     */
    protected function mapChildTypeToPhp(MergeTagChild $child): string
    {
        return match ($child->type) {
            'string', 'text' => 'string',
            'integer', 'int' => 'int',
            'number', 'float' => 'float',
            'boolean', 'bool' => 'bool',
            'array' => 'array',
            default => 'string',
        };
    }

    /**
     * Convert a merge tag key to a proper camelCase property name.
     *
     * Handles various formats: snake_case, SCREAMING_SNAKE_CASE, camelCase, etc.
     */
    protected function keyToPropertyName(string $key): string
    {
        // First lowercase the entire string to handle SCREAMING_SNAKE_CASE
        // Then convert to camelCase
        return Str::camel(Str::lower($key));
    }

    /**
     * Get the class name for a nested DTO (array item).
     *
     * Singularizes the key since nested DTOs represent individual items in an array.
     * e.g., INVOICE_ITEMS -> InvoiceItem, order_lines -> OrderLine
     */
    protected function getNestedClassName(string $parentClassName, string $key): string
    {
        // Convert to studly case, then singularize
        $studlyKey = Str::studly(Str::lower($key));
        $singularKey = Str::singular($studlyKey);

        return $parentClassName.$singularKey.'Data';
    }

    /**
     * Get the stub file content.
     */
    protected function getStubContent(): string
    {
        $stubPath = __DIR__.'/../../stubs/template-dto.stub';

        return $this->files->get($stubPath);
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
