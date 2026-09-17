<?php

use Lettr\Laravel\Support\BladeToSparkpostConverter;

beforeEach(function () {
    $this->converter = new BladeToSparkpostConverter;
});

describe('variable conversion', function () {
    it('converts simple variables', function () {
        $blade = '{{ $variable }}';
        $expected = '{{variable}}';

        expect($this->converter->convert($blade))->toBe($expected);
    });

    it('converts object property access', function () {
        $blade = '{{ $user->name }}';
        $expected = '{{user.name}}';

        expect($this->converter->convert($blade))->toBe($expected);
    });

    it('converts array bracket access', function () {
        $blade = "{{ \$user['name'] }}";
        $expected = '{{user.name}}';

        expect($this->converter->convert($blade))->toBe($expected);
    });

    it('converts double-quoted array bracket access', function () {
        $blade = '{{ $user["name"] }}';
        $expected = '{{user.name}}';

        expect($this->converter->convert($blade))->toBe($expected);
    });

    it('converts nested property access', function () {
        $blade = '{{ $user->profile->name }}';
        $expected = '{{user.profile.name}}';

        expect($this->converter->convert($blade))->toBe($expected);
    });

    it('converts mixed property and array access', function () {
        $blade = "{{ \$user->profile['address'] }}";
        $expected = '{{user.profile.address}}';

        expect($this->converter->convert($blade))->toBe($expected);
    });

    it('handles null coalescing by extracting variable', function () {
        $blade = "{{ \$name ?? 'default' }}";
        $expected = '{{name}}';

        expect($this->converter->convert($blade))->toBe($expected);
    });

    it('handles null coalescing with nested property', function () {
        $blade = "{{ \$user->name ?? 'Guest' }}";
        $expected = '{{user.name}}';

        expect($this->converter->convert($blade))->toBe($expected);
    });

    it('strips method calls and extracts base variable', function () {
        $blade = "{{ \$date->format('Y-m-d') }}";
        $expected = '{{date}}';

        expect($this->converter->convert($blade))->toBe($expected);
    });

    it('handles method calls on nested property', function () {
        $blade = "{{ \$order->created_at->format('M d, Y') }}";
        $expected = '{{order.created_at}}';

        expect($this->converter->convert($blade))->toBe($expected);
    });

    it('converts config helper to uppercase merge tag', function () {
        $blade = "{{ config('app.name') }}";
        $expected = '{{APP_NAME}}';

        expect($this->converter->convert($blade))->toBe($expected);
    });

    it('converts config helper with default value', function () {
        $blade = "{{ config('app.name', 'LETTR') }}";
        $expected = '{{APP_NAME}}';

        expect($this->converter->convert($blade))->toBe($expected);
    });

    it('converts config helper wrapped in function', function () {
        $blade = "{{ strtoupper(config('app.name', 'LETTR')) }}";
        $expected = '{{APP_NAME}}';

        expect($this->converter->convert($blade))->toBe($expected);
    });

    it('converts config helper with nested key', function () {
        $blade = "{{ config('mail.from.address') }}";
        $expected = '{{MAIL_FROM_ADDRESS}}';

        expect($this->converter->convert($blade))->toBe($expected);
    });
});

describe('raw echo conversion', function () {
    it('converts raw echoes to triple mustache', function () {
        $blade = '{!! $variable !!}';
        $expected = '{{{variable}}}';

        expect($this->converter->convert($blade))->toBe($expected);
    });

    it('converts raw echoes with property access', function () {
        $blade = '{!! $user->bio !!}';
        $expected = '{{{user.bio}}}';

        expect($this->converter->convert($blade))->toBe($expected);
    });

    it('converts raw echoes with array access', function () {
        $blade = "{!! \$content['html'] !!}";
        $expected = '{{{content.html}}}';

        expect($this->converter->convert($blade))->toBe($expected);
    });
});

describe('comment conversion', function () {
    it('converts blade comments', function () {
        $blade = '{{-- This is a comment --}}';
        $expected = '{{!-- This is a comment --}}';

        expect($this->converter->convert($blade))->toBe($expected);
    });

    it('converts multiline comments', function () {
        $blade = "{{-- This is a\nmultiline comment --}}";
        $expected = "{{!-- This is a\nmultiline comment --}}";

        expect($this->converter->convert($blade))->toBe($expected);
    });

    it('preserves content inside comments without conversion', function () {
        $blade = '{{-- {{ $var }} should not be converted --}}';
        $expected = '{{!-- {{ $var }} should not be converted --}}';

        expect($this->converter->convert($blade))->toBe($expected);
    });
});

describe('foreach loop conversion', function () {
    it('converts basic foreach loop', function () {
        $blade = '@foreach($items as $item)
{{ $item->name }}
@endforeach';
        $expected = '{{#each items}}
{{this.name}}
{{/each}}';

        expect($this->converter->convert($blade))->toBe($expected);
    });

    it('converts foreach with key-value pair', function () {
        $blade = '@foreach($users as $key => $user)
{{ $user->name }}
@endforeach';
        $expected = '{{#each users}}
{{this.name}}
{{/each}}';

        expect($this->converter->convert($blade))->toBe($expected);
    });

    it('converts loop variables to Sparkpost equivalents', function () {
        $blade = '@foreach($items as $item)
@if($loop->first)First!@endif
Index: {{ $loop->index }}
@if($loop->last)Last!@endif
@endforeach';
        $expected = '{{#each items}}
{{#if @first}}First!{{/if}}
Index: {{@index}}
{{#if @last}}Last!{{/if}}
{{/each}}';

        expect($this->converter->convert($blade))->toBe($expected);
    });

    it('round-trips a pulled loop with a null fallback and nested array access', function () {
        $blade = "@foreach(\$orders ?? [] as \$order){{ \$order['customer']['name'] }}@endforeach";

        expect($this->converter->convert($blade))->toBe('{{#each orders}}{{this.customer.name}}{{/each}}');
    });

    it('converts array access within loop', function () {
        $blade = "@foreach(\$items as \$item)
{{ \$item['name'] }}
@endforeach";
        $expected = '{{#each items}}
{{this.name}}
{{/each}}';

        expect($this->converter->convert($blade))->toBe($expected);
    });

    it('converts standalone item variable', function () {
        $blade = '@foreach($items as $item)
{{ $item }}
@endforeach';
        $expected = '{{#each items}}
{{this}}
{{/each}}';

        expect($this->converter->convert($blade))->toBe($expected);
    });

    it('converts nested property access in loop', function () {
        $blade = '@foreach($orders as $order)
{{ $order->customer->name }}
@endforeach';
        $expected = '{{#each orders}}
{{this.customer.name}}
{{/each}}';

        expect($this->converter->convert($blade))->toBe($expected);
    });
});

describe('conditional conversion', function () {
    it('converts basic if statement', function () {
        $blade = '@if($condition)
Content
@endif';
        $expected = '{{#if condition}}
Content
{{/if}}';

        expect($this->converter->convert($blade))->toBe($expected);
    });

    it('converts if-else statement', function () {
        $blade = '@if($condition)
True content
@else
False content
@endif';
        $expected = '{{#if condition}}
True content
{{else}}
False content
{{/if}}';

        expect($this->converter->convert($blade))->toBe($expected);
    });

    it('converts if-elseif-else statement', function () {
        $blade = '@if($first)
First
@elseif($second)
Second
@else
Default
@endif';
        $expected = '{{#if first}}
First
{{else if second}}
Second
{{else}}
Default
{{/if}}';

        expect($this->converter->convert($blade))->toBe($expected);
    });

    it('converts unless statement', function () {
        $blade = '@unless($hidden)
Visible content
@endunless';
        $expected = '{{#unless hidden}}
Visible content
{{/unless}}';

        expect($this->converter->convert($blade))->toBe($expected);
    });

    it('converts isset directive', function () {
        $blade = '@isset($name)
Hello {{ $name }}
@endisset';
        $expected = '{{#if name}}
Hello {{name}}
{{/if}}';

        expect($this->converter->convert($blade))->toBe($expected);
    });

    it('converts empty directive', function () {
        $blade = '@empty($items)
No items found
@endempty';
        $expected = '{{#unless items}}
No items found
{{/unless}}';

        expect($this->converter->convert($blade))->toBe($expected);
    });

    it('converts condition with property access', function () {
        $blade = '@if($user->isAdmin)
Admin panel
@endif';
        $expected = '{{#if user.isAdmin}}
Admin panel
{{/if}}';

        expect($this->converter->convert($blade))->toBe($expected);
    });

    it('converts negated condition', function () {
        $blade = '@if(!$hidden)
Visible
@endif';
        $expected = '{{#if !hidden}}
Visible
{{/if}}';

        expect($this->converter->convert($blade))->toBe($expected);
    });
});

describe('complex templates', function () {
    it('converts a complete email template', function () {
        $blade = '<!DOCTYPE html>
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

    @isset($promoCode)
    <p>Use code: {{ $promoCode }}</p>
    @endisset
</body>
</html>';

        $expected = '<!DOCTYPE html>
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

        expect($this->converter->convert($blade))->toBe($expected);
    });

    it('preserves non-blade HTML content', function () {
        $blade = '<div class="container">
    <span style="color: red;">{{ $message }}</span>
</div>';
        $expected = '<div class="container">
    <span style="color: red;">{{message}}</span>
</div>';

        expect($this->converter->convert($blade))->toBe($expected);
    });

    it('handles multiple variables in one line', function () {
        $blade = '<p>{{ $firstName }} {{ $lastName }} ({{ $email }})</p>';
        $expected = '<p>{{firstName}} {{lastName}} ({{email}})</p>';

        expect($this->converter->convert($blade))->toBe($expected);
    });
});

describe('edge cases', function () {
    it('handles empty content', function () {
        expect($this->converter->convert(''))->toBe('');
    });

    it('handles content with no blade syntax', function () {
        $html = '<html><body><p>Plain HTML</p></body></html>';
        expect($this->converter->convert($html))->toBe($html);
    });

    it('does not double-convert already converted sparkpost tags', function () {
        $content = '{{#if condition}}Content{{/if}}';
        expect($this->converter->convert($content))->toBe($content);
    });

    it('handles whitespace variations in directives', function () {
        $blade = '@if(  $condition  )
Content
@endif';
        $expected = '{{#if condition}}
Content
{{/if}}';

        expect($this->converter->convert($blade))->toBe($expected);
    });

    it('handles variable with underscore', function () {
        $blade = '{{ $first_name }}';
        $expected = '{{first_name}}';

        expect($this->converter->convert($blade))->toBe($expected);
    });

    it('handles deeply nested properties', function () {
        $blade = '{{ $user->profile->settings->theme }}';
        $expected = '{{user.profile.settings.theme}}';

        expect($this->converter->convert($blade))->toBe($expected);
    });
});
