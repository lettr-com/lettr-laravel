<?php

declare(strict_types=1);

namespace Lettr\Laravel\Support;

use Illuminate\Support\Str;

class SparkpostToBladeConverter
{
    /**
     * Stack to track nested loop variable names.
     *
     * @var array<int, string>
     */
    protected array $loopStack = [];

    /**
     * Convert Sparkpost merge tag syntax to Blade template syntax.
     */
    public function convert(string $sparkpostContent): string
    {
        $content = $sparkpostContent;

        // Reset loop stack for each conversion
        $this->loopStack = [];

        // Order matters:
        // 1. Comments first to avoid converting content inside comments
        // 2. Each/foreach next to track loop context for this.property conversion
        // 3. Conditionals before echoes
        // 4. Unescaped echoes (triple mustache) before regular echoes
        // 5. Regular echoes last
        $content = $this->convertComments($content);
        $content = $this->convertEach($content);
        $content = $this->convertConditionals($content);
        $content = $this->convertUnescapedEchoes($content);
        $content = $this->convertEchoes($content);

        return $content;
    }

    /**
     * Convert Sparkpost comments to Blade comments.
     * {{!-- comment --}} → {{-- comment --}}
     */
    protected function convertComments(string $content): string
    {
        return preg_replace(
            '/\{\{!--\s*(.*?)\s*--\}\}/s',
            '{{-- $1 --}}',
            $content
        ) ?? $content;
    }

    /**
     * Convert Sparkpost unescaped echoes (triple mustache) to Blade raw echoes.
     * {{{variable}}} → {!! $variable !!}
     */
    protected function convertUnescapedEchoes(string $content): string
    {
        return preg_replace_callback(
            '/\{\{\{(.+?)\}\}\}/s',
            fn (array $matches) => '{!! '.$this->convertVariableExpression(trim($matches[1])).' !!}',
            $content
        ) ?? $content;
    }

    /**
     * Convert standard Sparkpost echoes to Blade echoes.
     * {{variable}} → {{ $variable }}
     */
    protected function convertEchoes(string $content): string
    {
        return preg_replace_callback(
            '/\{\{(?!\{)(?!--)(.+?)\}\}/s',
            function (array $matches) {
                $inner = trim($matches[1]);

                // Skip if it looks like a Blade comment already
                if (str_starts_with($inner, '--')) {
                    return $matches[0];
                }

                // Skip if it's a block tag (starts with # or / or else)
                if (preg_match('/^[#\/!]/', $inner) || str_starts_with($inner, 'else')) {
                    return $matches[0];
                }

                return '{{ '.$this->convertVariableExpression($inner).' }}';
            },
            $content
        ) ?? $content;
    }

    /**
     * Convert {{#each}} blocks to @foreach loops.
     */
    protected function convertEach(string $content): string
    {
        // Process each blocks from inside out to handle nesting
        while (preg_match('/\{\{#each\s+(\w+)\}\}/', $content, $match, \PREG_OFFSET_CAPTURE)) {
            $fullMatch = $match[0][0];
            $collection = $match[1][0];
            $startPos = $match[0][1];

            // Determine item variable name using singular form
            $itemVar = Str::singular($collection);

            // If singular is same as collection, append 'Item'
            if ($itemVar === $collection) {
                $itemVar = $collection.'Item';
            }

            // Push to loop stack for this.property conversion
            $this->loopStack[] = $itemVar;

            // Find the matching {{/each}}
            $searchStart = $startPos + strlen($fullMatch);
            $endPos = $this->findMatchingEndEach($content, $searchStart);

            if ($endPos === false) {
                // No matching {{/each}} found, skip this one
                array_pop($this->loopStack);

                break;
            }

            // Get the block content between {{#each}} and {{/each}}
            $blockContent = substr($content, $searchStart, $endPos - $searchStart);

            // Convert loop variables within the block
            $blockContent = $this->convertEachBlockVariables($blockContent, $itemVar);

            // Pop from loop stack
            array_pop($this->loopStack);

            // Reconstruct the content
            $beforeBlock = substr($content, 0, $startPos);
            $afterBlock = substr($content, $endPos + strlen('{{/each}}'));

            $content = $beforeBlock.'@foreach($'.$collection.' as $'.$itemVar.')'.$blockContent.'@endforeach'.$afterBlock;
        }

        return $content;
    }

    /**
     * Find the position of the matching {{/each}} for a {{#each}}.
     */
    protected function findMatchingEndEach(string $content, int $searchStart): int|false
    {
        $depth = 1;
        $pos = $searchStart;
        $length = strlen($content);

        while ($pos < $length && $depth > 0) {
            // Look for the next {{#each or {{/each}}
            $nextEach = strpos($content, '{{#each', $pos);
            $nextEndEach = strpos($content, '{{/each}}', $pos);

            if ($nextEndEach === false) {
                return false;
            }

            if ($nextEach !== false && $nextEach < $nextEndEach) {
                // Found nested {{#each}}
                $depth++;
                $pos = $nextEach + strlen('{{#each');
            } else {
                // Found {{/each}}
                $depth--;
                if ($depth === 0) {
                    return $nextEndEach;
                }
                $pos = $nextEndEach + strlen('{{/each}}');
            }
        }

        return false;
    }

    /**
     * Convert variables within an each block, replacing this. with $item->.
     */
    protected function convertEachBlockVariables(string $blockContent, string $itemVar): string
    {
        // Convert this.property.nested to $item->property->nested
        $blockContent = preg_replace_callback(
            '/\bthis\.(\w+(?:\.\w+)*)/',
            function (array $matches) use ($itemVar) {
                $properties = str_replace('.', '->', $matches[1]);

                return '$'.$itemVar.'->'.$properties;
            },
            $blockContent
        ) ?? $blockContent;

        // Convert standalone "this" to $item
        $blockContent = preg_replace(
            '/\bthis\b(?!\.)/',
            '$'.$itemVar,
            $blockContent
        ) ?? $blockContent;

        // Convert Sparkpost loop variables to Blade $loop equivalents
        $blockContent = $this->convertLoopVariables($blockContent);

        return $blockContent;
    }

    /**
     * Convert Sparkpost @index, @first, @last to Blade $loop equivalents.
     */
    protected function convertLoopVariables(string $content): string
    {
        $replacements = [
            '/@index\b/' => '$loop->index',
            '/@first\b/' => '$loop->first',
            '/@last\b/' => '$loop->last',
        ];

        foreach ($replacements as $pattern => $replacement) {
            $content = preg_replace($pattern, $replacement, $content) ?? $content;
        }

        return $content;
    }

    /**
     * Convert Sparkpost conditional directives to Blade equivalents.
     */
    protected function convertConditionals(string $content): string
    {
        // {{#if condition}} → @if($condition)
        $content = preg_replace_callback(
            '/\{\{#if\s+(.+?)\}\}/',
            fn (array $matches) => '@if('.$this->convertConditionExpression(trim($matches[1])).')',
            $content
        ) ?? $content;

        // {{else if condition}} → @elseif($condition)
        $content = preg_replace_callback(
            '/\{\{else\s+if\s+(.+?)\}\}/',
            fn (array $matches) => '@elseif('.$this->convertConditionExpression(trim($matches[1])).')',
            $content
        ) ?? $content;

        // {{else}} → @else
        $content = preg_replace('/\{\{else\}\}/', '@else', $content) ?? $content;

        // {{/if}} → @endif
        $content = preg_replace('/\{\{\/if\}\}/', '@endif', $content) ?? $content;

        // {{#unless condition}} → @unless($condition)
        $content = preg_replace_callback(
            '/\{\{#unless\s+(.+?)\}\}/',
            fn (array $matches) => '@unless('.$this->convertConditionExpression(trim($matches[1])).')',
            $content
        ) ?? $content;

        // {{/unless}} → @endunless
        $content = preg_replace('/\{\{\/unless\}\}/', '@endunless', $content) ?? $content;

        return $content;
    }

    /**
     * Convert a Sparkpost variable expression to Blade format.
     * variable → $variable
     * user.name → $user->name
     */
    protected function convertVariableExpression(string $expression): string
    {
        $expression = trim($expression);

        // Handle negation
        $negated = false;
        if (str_starts_with($expression, '!')) {
            $negated = true;
            $expression = ltrim(substr($expression, 1));
        }

        // If already a PHP variable (starts with $), return as-is
        if (str_starts_with($expression, '$')) {
            return ($negated ? '!' : '').$expression;
        }

        // Convert dot notation to arrow notation for property access
        // First part gets the $ prefix, rest uses ->
        if (str_contains($expression, '.')) {
            $parts = explode('.', $expression);
            $expression = '$'.array_shift($parts).'->'.implode('->', $parts);
        } else {
            $expression = '$'.$expression;
        }

        return ($negated ? '!' : '').$expression;
    }

    /**
     * Convert a condition expression for use in Blade conditionals.
     */
    protected function convertConditionExpression(string $expression): string
    {
        return $this->convertVariableExpression($expression);
    }
}
