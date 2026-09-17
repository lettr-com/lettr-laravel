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
     * @var array<int, array{class: string, path: string, overwritten: bool}>
     */
    protected array $generatedDtos = [];

    public function __construct(
        protected readonly Filesystem $files,
    ) {}

    /**
     * Generate DTO classes for the given merge tags.
     *
     * @param  array<int, MergeTag>  $mergeTags
     * @return array{class: string, path: string, overwritten: bool}|null Returns null if no merge tags
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
     * @return array<int, array{class: string, path: string, overwritten: bool}>
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
        $dtoPath = TemplatesConfig::path('dto_path');
        $namespace = TemplatesConfig::namespace('dto_namespace');

        $fullPath = $dtoPath.'/'.$className.'.php';
        $relativePath = str_replace(base_path().'/', '', $fullPath);
        $fullyQualifiedClass = $namespace.'\\'.$className;

        $propertyNames = $this->propertyNames(array_map(fn (MergeTag $tag): string => $tag->key, $mergeTags));
        $nestedClassNames = $this->nestedClassNames($className, $mergeTags);

        // Generate nested DTOs first (for array loops)
        foreach ($mergeTags as $mergeTag) {
            if ($this->hasNestedChildren($mergeTag)) {
                $this->generateNestedDto($nestedClassNames[$mergeTag->key], $mergeTag->children ?? [], $dryRun);
            }
        }

        // Generate the main DTO
        $properties = $this->generateProperties($mergeTags, $propertyNames);
        $toArrayBody = $this->generateToArrayBody($mergeTags, $propertyNames, $nestedClassNames);
        $docblock = $this->generateDocblock($mergeTags, $propertyNames, $nestedClassNames);
        $imports = $this->generateImports($mergeTags, $nestedClassNames);

        $stub = $this->getStubContent();
        $content = str_replace(
            ['{{ namespace }}', '{{ imports }}', '{{ class }}', '{{ docblock }}', '{{ properties }}', '{{ toArrayBody }}'],
            [$namespace, $imports, $className, $docblock, $properties, $toArrayBody],
            $stub
        );

        $overwritten = $this->files->exists($fullPath);

        if (! $dryRun) {
            $this->ensureDirectoryExists($dtoPath);
            $this->files->put($fullPath, $content);
        }

        $this->generatedDtos[] = [
            'class' => $fullyQualifiedClass,
            'path' => $relativePath,
            'overwritten' => $overwritten,
        ];
    }

    /**
     * Generate a nested DTO class for child merge tags.
     *
     * @param  array<int, MergeTagChild>  $children
     */
    protected function generateNestedDto(string $className, array $children, bool $dryRun): void
    {
        $dtoPath = TemplatesConfig::path('dto_path');
        $namespace = TemplatesConfig::namespace('dto_namespace');

        $fullPath = $dtoPath.'/'.$className.'.php';
        $relativePath = str_replace(base_path().'/', '', $fullPath);
        $fullyQualifiedClass = $namespace.'\\'.$className;

        $propertyNames = $this->propertyNames(array_map(fn (MergeTagChild $child): string => $child->key, $children));
        $properties = $this->generateChildProperties($children, $propertyNames);
        $toArrayBody = $this->generateChildToArrayBody($children, $propertyNames);
        $imports = '';

        $stub = $this->getStubContent();
        $content = str_replace(
            ['{{ namespace }}', '{{ imports }}', '{{ class }}', '{{ docblock }}', '{{ properties }}', '{{ toArrayBody }}'],
            [$namespace, $imports, $className, '', $properties, $toArrayBody],
            $stub
        );

        $overwritten = $this->files->exists($fullPath);

        if (! $dryRun) {
            $this->ensureDirectoryExists($dtoPath);
            $this->files->put($fullPath, $content);
        }

        $this->generatedDtos[] = [
            'class' => $fullyQualifiedClass,
            'path' => $relativePath,
            'overwritten' => $overwritten,
        ];
    }

    /**
     * Generate import statements for nested DTO classes.
     *
     * @param  array<int, MergeTag>  $mergeTags
     * @param  array<string, string>  $nestedClassNames
     */
    protected function generateImports(array $mergeTags, array $nestedClassNames): string
    {
        $imports = [];
        $namespace = TemplatesConfig::namespace('dto_namespace');

        foreach ($mergeTags as $tag) {
            if ($this->hasNestedChildren($tag)) {
                $imports[] = "use {$namespace}\\{$nestedClassNames[$tag->key]};";
            }
        }

        if (empty($imports)) {
            return '';
        }

        return "\n".implode("\n", $imports);
    }

    /**
     * Generate PHPDoc block for constructor with array type hints.
     *
     * @param  array<int, MergeTag>  $mergeTags
     * @param  array<string, string>  $propertyNames
     * @param  array<string, string>  $nestedClassNames
     */
    protected function generateDocblock(array $mergeTags, array $propertyNames, array $nestedClassNames): string
    {
        $params = [];

        foreach ($mergeTags as $tag) {
            if ($this->hasNestedChildren($tag)) {
                $params[] = "     * @param {$nestedClassNames[$tag->key]}[]|null \${$propertyNames[$tag->key]}";
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
     * @param  array<string, string>  $propertyNames
     */
    protected function generateProperties(array $mergeTags, array $propertyNames): string
    {
        $lines = [];

        // Sort: required properties first, then optional
        $required = array_filter($mergeTags, fn (MergeTag $tag): bool => $tag->required);
        $optional = array_filter($mergeTags, fn (MergeTag $tag): bool => ! $tag->required);

        foreach ($required as $tag) {
            $lines[] = $this->generatePropertyLine($tag, $propertyNames[$tag->key], true);
        }

        foreach ($optional as $tag) {
            $lines[] = $this->generatePropertyLine($tag, $propertyNames[$tag->key], false);
        }

        return implode("\n", $lines);
    }

    /**
     * Generate a single property line.
     */
    protected function generatePropertyLine(MergeTag $tag, string $propertyName, bool $isRequired): string
    {
        $phpType = $this->mapTypeToPhp($tag);

        if ($isRequired) {
            return "        public {$phpType} \${$propertyName},";
        }

        return "        public ?{$phpType} \${$propertyName} = null,";
    }

    /**
     * Generate constructor properties for child merge tags.
     *
     * @param  array<int, MergeTagChild>  $children
     * @param  array<string, string>  $propertyNames
     */
    protected function generateChildProperties(array $children, array $propertyNames): string
    {
        $lines = [];

        // Children don't have required flag, so all are optional
        foreach ($children as $child) {
            $propertyName = $propertyNames[$child->key];
            $phpType = $this->mapChildTypeToPhp($child);
            $lines[] = "        public ?{$phpType} \${$propertyName} = null,";
        }

        return implode("\n", $lines);
    }

    /**
     * Generate the toArray body for merge tags.
     *
     * @param  array<int, MergeTag>  $mergeTags
     * @param  array<string, string>  $propertyNames
     * @param  array<string, string>  $nestedClassNames
     */
    protected function generateToArrayBody(array $mergeTags, array $propertyNames, array $nestedClassNames): string
    {
        $lines = [];

        foreach ($mergeTags as $tag) {
            $propertyName = $propertyNames[$tag->key];
            $key = var_export($tag->key, true);

            if ($this->hasNestedChildren($tag)) {
                // Array of nested DTOs - map each item to array with typed closure
                $map = "array_map(fn ({$nestedClassNames[$tag->key]} \$item) => \$item->toArray(), \$this->{$propertyName})";

                // An optional loop defaults to null, which array_map() rejects
                $lines[] = $tag->required
                    ? "            {$key} => {$map},"
                    : "            {$key} => \$this->{$propertyName} === null ? null : {$map},";
            } else {
                $lines[] = "            {$key} => \$this->{$propertyName},";
            }
        }

        return implode("\n", $lines);
    }

    /**
     * Generate the toArray body for child merge tags.
     *
     * @param  array<int, MergeTagChild>  $children
     * @param  array<string, string>  $propertyNames
     */
    protected function generateChildToArrayBody(array $children, array $propertyNames): string
    {
        $lines = [];

        foreach ($children as $child) {
            $key = var_export($child->key, true);
            $lines[] = "            {$key} => \$this->{$propertyNames[$child->key]},";
        }

        return implode("\n", $lines);
    }

    /**
     * Map a merge tag type to a PHP type.
     */
    protected function mapTypeToPhp(MergeTag $tag): string
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
     * Map each merge tag key to its constructor property name.
     *
     * Keys that camelCase to the same name (`FIRST_NAME` and `first_name`) keep
     * their raw key as the property instead — raw keys are distinct and already
     * valid PHP identifiers, and a duplicate parameter is a fatal error.
     *
     * @param  array<int, string>  $keys
     * @return array<string, string>
     */
    protected function propertyNames(array $keys): array
    {
        $names = [];

        foreach ($keys as $key) {
            $names[$key] = PhpIdentifier::property($key);
        }

        $counts = array_count_values($names);

        foreach ($names as $key => $name) {
            if ($counts[$name] > 1) {
                $names[$key] = $key;
            }
        }

        return $names;
    }

    /**
     * Map each loop merge tag key to the class name of its item DTO.
     *
     * Keys that singularize to the same name (`item` and `items`) are numbered,
     * because PHP class names — and most filesystems — ignore case.
     *
     * @param  array<int, MergeTag>  $mergeTags
     * @return array<string, string>
     */
    protected function nestedClassNames(string $parentClassName, array $mergeTags): array
    {
        $names = [];
        $seen = [];

        foreach ($mergeTags as $tag) {
            if (! $this->hasNestedChildren($tag)) {
                continue;
            }

            $base = $parentClassName.$this->getNestedClassFragment($tag->key);
            $name = $base.'Data';
            $suffix = 2;

            while (isset($seen[strtolower($name)])) {
                $name = $base.$suffix++.'Data';
            }

            $seen[strtolower($name)] = true;
            $names[$tag->key] = $name;
        }

        return $names;
    }

    /**
     * Get the singular StudlyCase fragment a loop key contributes to its item DTO name.
     */
    protected function getNestedClassFragment(string $key): string
    {
        return Str::singular(PhpIdentifier::studlyKey($key));
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
        return PhpIdentifier::className($slug);
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
        $namespace = TemplatesConfig::namespace('dto_namespace');

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
