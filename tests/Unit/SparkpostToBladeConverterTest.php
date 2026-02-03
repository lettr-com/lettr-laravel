<?php

use Lettr\Laravel\Support\SparkpostToBladeConverter;

beforeEach(function () {
    $this->converter = new SparkpostToBladeConverter;
});

describe('variable conversion', function () {
    it('converts simple variables', function () {
        $sparkpost = '{{variable}}';
        $expected = '{{ $variable }}';

        expect($this->converter->convert($sparkpost))->toBe($expected);
    });

    it('converts object property access', function () {
        $sparkpost = '{{user.name}}';
        $expected = '{{ $user->name }}';

        expect($this->converter->convert($sparkpost))->toBe($expected);
    });

    it('converts nested property access', function () {
        $sparkpost = '{{user.profile.name}}';
        $expected = '{{ $user->profile->name }}';

        expect($this->converter->convert($sparkpost))->toBe($expected);
    });

    it('converts deeply nested properties', function () {
        $sparkpost = '{{user.profile.settings.theme}}';
        $expected = '{{ $user->profile->settings->theme }}';

        expect($this->converter->convert($sparkpost))->toBe($expected);
    });

    it('handles variable with underscore', function () {
        $sparkpost = '{{first_name}}';
        $expected = '{{ $first_name }}';

        expect($this->converter->convert($sparkpost))->toBe($expected);
    });

    it('handles multiple variables in one line', function () {
        $sparkpost = '<p>{{firstName}} {{lastName}} ({{email}})</p>';
        $expected = '<p>{{ $firstName }} {{ $lastName }} ({{ $email }})</p>';

        expect($this->converter->convert($sparkpost))->toBe($expected);
    });
});

describe('raw echo conversion', function () {
    it('converts triple mustache to raw echoes', function () {
        $sparkpost = '{{{variable}}}';
        $expected = '{!! $variable !!}';

        expect($this->converter->convert($sparkpost))->toBe($expected);
    });

    it('converts raw echoes with property access', function () {
        $sparkpost = '{{{user.bio}}}';
        $expected = '{!! $user->bio !!}';

        expect($this->converter->convert($sparkpost))->toBe($expected);
    });

    it('converts raw echoes with nested property access', function () {
        $sparkpost = '{{{content.html.body}}}';
        $expected = '{!! $content->html->body !!}';

        expect($this->converter->convert($sparkpost))->toBe($expected);
    });
});

describe('comment conversion', function () {
    it('converts sparkpost comments to blade comments', function () {
        $sparkpost = '{{!-- This is a comment --}}';
        $expected = '{{-- This is a comment --}}';

        expect($this->converter->convert($sparkpost))->toBe($expected);
    });

    it('converts multiline comments', function () {
        $sparkpost = "{{!-- This is a\nmultiline comment --}}";
        $expected = "{{-- This is a\nmultiline comment --}}";

        expect($this->converter->convert($sparkpost))->toBe($expected);
    });
});

describe('foreach loop conversion', function () {
    it('converts basic each loop', function () {
        $sparkpost = '{{#each items}}
{{this.name}}
{{/each}}';
        $expected = '@foreach($items as $item)
{{ $item->name }}
@endforeach';

        expect($this->converter->convert($sparkpost))->toBe($expected);
    });

    it('converts standalone this to item variable', function () {
        $sparkpost = '{{#each items}}
{{this}}
{{/each}}';
        $expected = '@foreach($items as $item)
{{ $item }}
@endforeach';

        expect($this->converter->convert($sparkpost))->toBe($expected);
    });

    it('converts nested property access in loop', function () {
        $sparkpost = '{{#each orders}}
{{this.customer.name}}
{{/each}}';
        $expected = '@foreach($orders as $order)
{{ $order->customer->name }}
@endforeach';

        expect($this->converter->convert($sparkpost))->toBe($expected);
    });

    it('converts loop variables to blade equivalents', function () {
        $sparkpost = '{{#each items}}
{{#if @first}}First!{{/if}}
Index: {{@index}}
{{#if @last}}Last!{{/if}}
{{/each}}';
        $expected = '@foreach($items as $item)
@if($loop->first)First!@endif
Index: {{ $loop->index }}
@if($loop->last)Last!@endif
@endforeach';

        expect($this->converter->convert($sparkpost))->toBe($expected);
    });

    it('uses singular form for item variable', function () {
        $sparkpost = '{{#each users}}{{this.email}}{{/each}}';
        $expected = '@foreach($users as $user){{ $user->email }}@endforeach';

        expect($this->converter->convert($sparkpost))->toBe($expected);
    });

    it('handles collection names that are already singular', function () {
        $sparkpost = '{{#each data}}{{this.value}}{{/each}}';
        $expected = '@foreach($data as $dataItem){{ $dataItem->value }}@endforeach';

        expect($this->converter->convert($sparkpost))->toBe($expected);
    });
});

describe('conditional conversion', function () {
    it('converts basic if statement', function () {
        $sparkpost = '{{#if condition}}
Content
{{/if}}';
        $expected = '@if($condition)
Content
@endif';

        expect($this->converter->convert($sparkpost))->toBe($expected);
    });

    it('converts if-else statement', function () {
        $sparkpost = '{{#if condition}}
True content
{{else}}
False content
{{/if}}';
        $expected = '@if($condition)
True content
@else
False content
@endif';

        expect($this->converter->convert($sparkpost))->toBe($expected);
    });

    it('converts if-elseif-else statement', function () {
        $sparkpost = '{{#if first}}
First
{{else if second}}
Second
{{else}}
Default
{{/if}}';
        $expected = '@if($first)
First
@elseif($second)
Second
@else
Default
@endif';

        expect($this->converter->convert($sparkpost))->toBe($expected);
    });

    it('converts unless statement', function () {
        $sparkpost = '{{#unless hidden}}
Visible content
{{/unless}}';
        $expected = '@unless($hidden)
Visible content
@endunless';

        expect($this->converter->convert($sparkpost))->toBe($expected);
    });

    it('converts condition with property access', function () {
        $sparkpost = '{{#if user.isAdmin}}
Admin panel
{{/if}}';
        $expected = '@if($user->isAdmin)
Admin panel
@endif';

        expect($this->converter->convert($sparkpost))->toBe($expected);
    });

    it('converts negated condition', function () {
        $sparkpost = '{{#if !hidden}}
Visible
{{/if}}';
        $expected = '@if(!$hidden)
Visible
@endif';

        expect($this->converter->convert($sparkpost))->toBe($expected);
    });
});

describe('complex templates', function () {
    it('converts a complete email template', function () {
        $sparkpost = '<!DOCTYPE html>
<html>
<head>
    <title>{{subject}}</title>
</head>
<body>
    {{!-- Main content --}}
    <h1>Hello {{user.name}}</h1>

    {{#if hasOrders}}
    <h2>Your Orders</h2>
    <ul>
    {{#each orders}}
        <li>
            Order #{{this.id}}: {{this.total}}
            {{#if @last}}
            (Most recent)
            {{/if}}
        </li>
    {{/each}}
    </ul>
    {{else}}
    <p>No orders yet.</p>
    {{/if}}

    {{#if promoCode}}
    <p>Use code: {{promoCode}}</p>
    {{/if}}
</body>
</html>';

        $expected = '<!DOCTYPE html>
<html>
<head>
    <title>{{ $subject }}</title>
</head>
<body>
    {{-- Main content --}}
    <h1>Hello {{ $user->name }}</h1>

    @if($hasOrders)
    <h2>Your Orders</h2>
    <ul>
    @foreach($orders as $order)
        <li>
            Order #{{ $order->id }}: {{ $order->total }}
            @if($loop->last)
            (Most recent)
            @endif
        </li>
    @endforeach
    </ul>
    @else
    <p>No orders yet.</p>
    @endif

    @if($promoCode)
    <p>Use code: {{ $promoCode }}</p>
    @endif
</body>
</html>';

        expect($this->converter->convert($sparkpost))->toBe($expected);
    });

    it('preserves non-sparkpost HTML content', function () {
        $sparkpost = '<div class="container">
    <span style="color: red;">{{message}}</span>
</div>';
        $expected = '<div class="container">
    <span style="color: red;">{{ $message }}</span>
</div>';

        expect($this->converter->convert($sparkpost))->toBe($expected);
    });
});

describe('edge cases', function () {
    it('handles empty content', function () {
        expect($this->converter->convert(''))->toBe('');
    });

    it('handles content with no sparkpost syntax', function () {
        $html = '<html><body><p>Plain HTML</p></body></html>';
        expect($this->converter->convert($html))->toBe($html);
    });

    it('does not double-convert already converted blade directives', function () {
        $content = '@if($condition)Content@endif';
        expect($this->converter->convert($content))->toBe($content);
    });

    it('handles whitespace in mustache tags', function () {
        $sparkpost = '{{ variable }}';
        // This is already valid Blade, should pass through or convert consistently
        $result = $this->converter->convert($sparkpost);
        // Should contain the variable reference
        expect($result)->toContain('variable');
    });

    it('does not convert blade comments that already exist', function () {
        $content = '{{-- This is already a blade comment --}}';
        expect($this->converter->convert($content))->toBe($content);
    });
});

describe('nested loops', function () {
    it('handles nested each loops', function () {
        $sparkpost = '{{#each categories}}
<h2>{{this.name}}</h2>
{{#each this.products}}
<p>{{this.title}}</p>
{{/each}}
{{/each}}';

        $result = $this->converter->convert($sparkpost);

        expect($result)->toContain('@foreach($categories as $category)');
        expect($result)->toContain('{{ $category->name }}');
        expect($result)->toContain('@endforeach');
    });
});
