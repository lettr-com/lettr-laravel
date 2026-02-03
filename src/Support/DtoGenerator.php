<?php

declare(strict_types=1);

namespace Lettr\Laravel\Support;

use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Str;
use Lettr\Dto\Template\MergeTag;
use Lettr\Dto\Template\MergeTagChild;

class DtoGenerator
{
    /**
     * @var array<int, array{class: string, path: string}>
     */
    protected array $generatedDtos = [];

    public function __construct(
        protected readonly Filesystem $files,
    ) {}

    /**
     * Generate DTO classes for the given merge tags.
     *
     * @param  array<int, MergeTag>  $mergeTags
     * @return array{class: string, path: string}|null Returns null if no merge tags
     */
    public function generate(string $templateSlug, array $mergeTags, bool $dryRun = false): ?array
    {
        if (empty($mergeTags)) {
            return null;
        }

        $this->generatedDtos = [];

        $baseClassName = $this->slugToClassName($templateSlug).'Data';
        $this->generateDto($baseClassName, $mergeTags, $dryRun);

        return $this->generatedDtos[0] ?? null;
    }

    /**
     * Get all generated DTOs (including nested ones).
     *
     * @return array<int, array{class: string, path: string}>
     */
    public function getGeneratedDtos(): array
    {
        return $this->generatedDtos;
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
        foreach ($mergeTags as $mergeTag) {
            if ($this->hasNestedChildren($mergeTag)) {
                $nestedClassName = $this->getNestedClassName($className, $mergeTag->key);
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
     */
    protected function keyToPropertyName(string $key): string
    {
        return Str::camel(Str::lower($key));
    }

    /**
     * Get the class name for a nested DTO (array item).
     */
    protected function getNestedClassName(string $parentClassName, string $key): string
    {
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
    public function slugToClassName(string $slug): string
    {
        return Str::studly($slug);
    }

    /**
     * Get the DTO class name for a template slug.
     */
    public function getDtoClassName(string $templateSlug): string
    {
        return $this->slugToClassName($templateSlug).'Data';
    }

    /**
     * Get the fully qualified DTO class name for a template slug.
     */
    public function getFullyQualifiedDtoClassName(string $templateSlug): string
    {
        $namespace = config('lettr.templates.dto_namespace');

        return $namespace.'\\'.$this->getDtoClassName($templateSlug);
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
}
