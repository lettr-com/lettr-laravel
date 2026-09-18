<?php

declare(strict_types=1);

namespace Lettr\Laravel\Support;

class BladeToSparkpostConverter
{
    /**
     * Convert Blade template syntax to Sparkpost merge tag syntax.
     */
    public function convert(string $bladeContent): string
    {
        $content = $bladeContent;

        // Order matters:
        // 1. Comments first to avoid converting content inside comments
        // 2. Foreach next to convert loop variables ($item->, $loop->) before echoes
        // 3. Conditionals before echoes to handle @if, @unless, etc.
        // 4. Raw echoes and regular echoes last
        $content = $this->convertComments($content);
        $content = $this->convertForeach($content);
        $content = $this->convertConditionals($content);
        $content = $this->convertRawEchoes($content);
        $content = $this->convertEchoes($content);

        return $content;
    }

    /**
     * Convert Blade comments to Sparkpost comments.
     * {{-- comment --}} → {{!-- comment --}}
     */
    protected function convertComments(string $content): string
    {
        return preg_replace(
            '/\{\{--\s*(.*?)\s*--\}\}/s',
            '{{!-- $1 --}}',
            $content
        ) ?? $content;
    }

    /**
     * Convert raw/unescaped Blade echoes to Sparkpost triple-mustache (unescaped).
     * {!! $variable !!} → {{{variable}}}
     */
    protected function convertRawEchoes(string $content): string
    {
        return preg_replace_callback(
            '/\{!!\s*(.+?)\s*!!\}/s',
            fn (array $matches) => '{{{'.$this->convertVariableExpression($matches[1]).'}}}',
            $content
        ) ?? $content;
    }

    /**
     * Convert standard Blade echoes to Sparkpost double-mustache.
     * {{ $variable }} → {{variable}}
     */
    protected function convertEchoes(string $content): string
    {
        return preg_replace_callback(
            '/\{\{\s*(.+?)\s*\}\}/s',
            function (array $matches) {
                // Skip if it looks like a Sparkpost tag already (starts with # or /)
                if (preg_match('/^[#\/!]/', trim($matches[1]))) {
                    return $matches[0];
                }

                return '{{'.$this->convertVariableExpression($matches[1]).'}}';
            },
            $content
        ) ?? $content;
    }

    /**
     * Convert @foreach loops to Sparkpost {{#each}} blocks.
     */
    protected function convertForeach(string $content): string
    {
        // Process foreach blocks from inside out to handle nesting
        while (preg_match('/@foreach\s*\(\s*\$(\w+)(?:\s*\?\?\s*\[\s*\])?\s+as\s+(?:\$\w+\s*=>\s*)?\$(\w+)\s*\)/', $content, $match, \PREG_OFFSET_CAPTURE)) {
            $fullMatch = $match[0][0];
            $collection = $match[1][0];
            $itemVar = $match[2][0];
            $startPos = $match[0][1];

            // Find the matching @endforeach
            $searchStart = $startPos + strlen($fullMatch);
            $endPos = $this->findMatchingEndforeach($content, $searchStart);

            if ($endPos === false) {
                // No matching @endforeach found, skip this one
                break;
            }

            // Get the block content between @foreach and @endforeach
            $blockContent = substr($content, $searchStart, $endPos - $searchStart);

            // Convert loop variables within the block
            $blockContent = $this->convertForeachBlockVariables($blockContent, $itemVar);

            // Reconstruct the content
            $beforeBlock = substr($content, 0, $startPos);
            $afterBlock = substr($content, $endPos + strlen('@endforeach'));

            $content = $beforeBlock.'{{#each '.$collection.'}}'.$blockContent.'{{/each}}'.$afterBlock;
        }

        return $content;
    }

    /**
     * Find the position of the matching @endforeach for a @foreach.
     */
    protected function findMatchingEndforeach(string $content, int $searchStart): int|false
    {
        $depth = 1;
        $pos = $searchStart;
        $length = strlen($content);

        while ($pos < $length) {
            // Look for the next @foreach or @endforeach
            $nextForeach = strpos($content, '@foreach', $pos);
            $nextEndforeach = strpos($content, '@endforeach', $pos);

            if ($nextEndforeach === false) {
                return false;
            }

            if ($nextForeach !== false && $nextForeach < $nextEndforeach) {
                // Found nested @foreach
                $depth++;
                $pos = $nextForeach + strlen('@foreach');
            } else {
                // Found @endforeach
                $depth--;
                if ($depth === 0) {
                    return $nextEndforeach;
                }
                $pos = $nextEndforeach + strlen('@endforeach');
            }
        }

        return false;
    }

    /**
     * Convert variables within a foreach block, replacing $item-> with this.
     */
    protected function convertForeachBlockVariables(string $blockContent, string $itemVar): string
    {
        // Convert $item-> to this.
        $blockContent = preg_replace(
            '/\$'.preg_quote($itemVar, '/').'->/',
            'this.',
            $blockContent
        ) ?? $blockContent;

        // Convert $item['key']['nested'] to this.key.nested
        $blockContent = preg_replace_callback(
            '/\$'.preg_quote($itemVar, '/').'((?:\[([\'"])\w+\2\])+)/',
            function (array $matches): string {
                preg_match_all('/\[[\'"](\w+)[\'"]\]/', $matches[1], $keys);

                return 'this.'.implode('.', $keys[1]);
            },
            $blockContent
        ) ?? $blockContent;

        // Convert standalone $item to this (when it's the whole expression)
        $blockContent = preg_replace(
            '/\$'.preg_quote($itemVar, '/').'(?![\w\[\-])/',
            'this',
            $blockContent
        ) ?? $blockContent;

        // Convert $loop variables
        $blockContent = $this->convertLoopVariables($blockContent);

        return $blockContent;
    }

    /**
     * Convert Blade $loop variables to Sparkpost equivalents.
     */
    protected function convertLoopVariables(string $content): string
    {
        $replacements = [
            '/\$loop->index/' => '@index',
            '/\$loop->first/' => '@first',
            '/\$loop->last/' => '@last',
        ];

        foreach ($replacements as $pattern => $replacement) {
            $content = preg_replace($pattern, $replacement, $content) ?? $content;
        }

        return $content;
    }

    /**
     * Convert Blade conditional directives to Sparkpost equivalents.
     */
    protected function convertConditionals(string $content): string
    {
        // @if($condition) → {{#if condition}}
        $content = preg_replace_callback(
            '/@if\s*\(\s*(.+?)\s*\)/',
            fn (array $matches) => '{{#if '.$this->convertConditionExpression($matches[1]).'}}',
            $content
        ) ?? $content;

        // @elseif($condition) → {{else if condition}}
        $content = preg_replace_callback(
            '/@elseif\s*\(\s*(.+?)\s*\)/',
            fn (array $matches) => '{{else if '.$this->convertConditionExpression($matches[1]).'}}',
            $content
        ) ?? $content;

        // @else → {{else}}
        $content = preg_replace('/@else(?!\s*if)/', '{{else}}', $content) ?? $content;

        // @endif → {{/if}}
        $content = preg_replace('/@endif/', '{{/if}}', $content) ?? $content;

        // @unless($condition) → {{#unless condition}}
        $content = preg_replace_callback(
            '/@unless\s*\(\s*(.+?)\s*\)/',
            fn (array $matches) => '{{#unless '.$this->convertConditionExpression($matches[1]).'}}',
            $content
        ) ?? $content;

        // @endunless → {{/unless}}
        $content = preg_replace('/@endunless/', '{{/unless}}', $content) ?? $content;

        // @isset($var) → {{#if var}}
        $content = preg_replace_callback(
            '/@isset\s*\(\s*(.+?)\s*\)/',
            fn (array $matches) => '{{#if '.$this->convertConditionExpression($matches[1]).'}}',
            $content
        ) ?? $content;

        // @endisset → {{/if}}
        $content = preg_replace('/@endisset/', '{{/if}}', $content) ?? $content;

        // @empty($var) → {{#unless var}}
        $content = preg_replace_callback(
            '/@empty\s*\(\s*(.+?)\s*\)/',
            fn (array $matches) => '{{#unless '.$this->convertConditionExpression($matches[1]).'}}',
            $content
        ) ?? $content;

        // @endempty → {{/unless}}
        $content = preg_replace('/@endempty/', '{{/unless}}', $content) ?? $content;

        return $content;
    }

    /**
     * Convert a Blade variable expression to Sparkpost format.
     * Handles: $var, $var->prop, $var['key'], $var ?? 'default', method calls, config()
     */
    protected function convertVariableExpression(string $expression): string
    {
        $expression = trim($expression);

        // Handle config() helper: config('app.name') or config('app.name', 'default')
        // Also handles wrapped functions like strtoupper(config('app.name'))
        if (preg_match('/config\s*\(\s*[\'"]([^\'"]+)[\'"]\s*(?:,\s*[^)]+)?\s*\)/', $expression, $matches)) {
            return $this->convertConfigKey($matches[1]);
        }

        // Handle null coalescing: $var ?? 'default' → extract just $var
        if (preg_match('/^(.+?)\s*\?\?\s*.+$/', $expression, $matches)) {
            $expression = trim($matches[1]);
        }

        // Handle ternary: $var ? 'yes' : 'no' → extract just $var
        if (preg_match('/^(.+?)\s*\?\s*.+\s*:\s*.+$/', $expression, $matches)) {
            $expression = trim($matches[1]);
        }

        // If it doesn't start with $, it might be a complex expression - return as-is
        if (! str_starts_with($expression, '$')) {
            // Check if it's a 'this.' expression (already converted loop variable)
            if (str_starts_with($expression, 'this.')) {
                return $this->convertPropertyAccess($expression);
            }

            return $expression;
        }

        // Remove the $ prefix
        $expression = substr($expression, 1);

        // Convert property/array access
        return $this->convertPropertyAccess($expression);
    }

    /**
     * Convert a config key to Sparkpost merge tag format.
     * app.name → APP_NAME
     */
    protected function convertConfigKey(string $key): string
    {
        return strtoupper(str_replace('.', '_', $key));
    }

    /**
     * Convert property and array access to dot notation.
     * user->name → user.name
     * user['name'] → user.name
     * user->profile->name → user.profile.name
     */
    protected function convertPropertyAccess(string $expression): string
    {
        // Remove method calls: $date->format('Y-m-d') → date
        $expression = preg_replace('/->(\w+)\([^)]*\).*$/', '', $expression) ?? $expression;

        // Convert -> to .
        $expression = str_replace('->', '.', $expression);

        // Convert ['key'] or ["key"] to .key
        $expression = preg_replace_callback(
            '/\[([\'"])(\w+)\1\]/',
            fn (array $matches) => '.'.$matches[2],
            $expression
        ) ?? $expression;

        // Remove any remaining array access with variables (not convertible)
        $expression = preg_replace('/\[[^\]]+\]/', '', $expression) ?? $expression;

        // Clean up any trailing dots
        return rtrim($expression, '.');
    }

    /**
     * Convert a condition expression for use in Sparkpost conditionals.
     */
    protected function convertConditionExpression(string $expression): string
    {
        $expression = trim($expression);

        // Simple variable check: $var or !$var
        if (preg_match('/^!?\s*\$(\w+)(?:->[\w.]+|\[.+?\])*\s*$/', $expression)) {
            $isNegated = str_starts_with($expression, '!');
            $expression = ltrim($expression, '! ');

            return ($isNegated ? '!' : '').$this->convertVariableExpression($expression);
        }

        // For more complex expressions, just convert variables within
        return preg_replace_callback(
            '/\$(\w+(?:->[\w]+|\[[\'"]?\w+[\'"]?\])*)/',
            fn (array $matches) => $this->convertVariableExpression($matches[0]),
            $expression
        ) ?? $expression;
    }
}
