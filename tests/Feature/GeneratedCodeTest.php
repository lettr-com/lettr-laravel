<?php

use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Str;
use Lettr\Collections\TemplateCollection;
use Lettr\Dto\Template\MergeTag;
use Lettr\Dto\Template\MergeTagChild;
use Lettr\Dto\Template\Template;
use Lettr\Dto\Template\TemplateDetail;
use Lettr\Laravel\LettrManager;
use Lettr\Laravel\Services\TemplateServiceWrapper;
use Lettr\Laravel\Support\SparkpostToBladeConverter;
use Lettr\Responses\GetMergeTagsResponse;
use Lettr\Responses\ListTemplatesResponse;
use Lettr\Responses\TemplatePagination;
use Lettr\ValueObjects\Timestamp;

/**
 * The other command tests mock the filesystem, so they can only assert on
 * strings. These write real files to a temp directory and check the generated
 * code parses and loads — in a separate PHP process, because a duplicate case
 * or parameter is a compile-time fatal error.
 */
function generatedTemplate(string $slug, ?string $name = null): Template
{
    return new Template(
        id: 1,
        name: $name ?? $slug,
        slug: $slug,
        projectId: 1,
        folderId: null,
        createdAt: Timestamp::now(),
        updatedAt: Timestamp::now(),
    );
}

function generatedTemplateDetail(string $slug, ?string $name = null, ?string $html = '<p>Hello</p>'): TemplateDetail
{
    return new TemplateDetail(
        id: 1,
        name: $name ?? $slug,
        slug: $slug,
        projectId: 1,
        folderId: null,
        activeVersion: 1,
        versionsCount: 1,
        html: $html,
        json: null,
        createdAt: Timestamp::now(),
        updatedAt: Timestamp::now(),
    );
}

/**
 * @param  array<int, Template>  $templates
 */
function generatedListPage(array $templates, int $page = 1, int $lastPage = 1): ListTemplatesResponse
{
    return new ListTemplatesResponse(
        templates: TemplateCollection::from($templates),
        pagination: new TemplatePagination(currentPage: $page, lastPage: $lastPage, perPage: 100, total: count($templates)),
    );
}

/**
 * Serve templates (with optional merge tags and names) from the mocked template service.
 *
 * @param  array<string, array{name?: string, html?: string|null, tags?: array<int, MergeTag>}>  $templates
 */
function serveTemplates(object $test, array $templates): void
{
    $list = [];

    foreach ($templates as $slug => $template) {
        $list[] = generatedTemplate($slug, $template['name'] ?? null);

        $test->templateService->shouldReceive('get')->with($slug)
            ->andReturn(generatedTemplateDetail($slug, $template['name'] ?? null, array_key_exists('html', $template) ? $template['html'] : '<p>Hello</p>'));

        $test->templateService->shouldReceive('getMergeTags')->with($slug, null, 1)
            ->andReturn(new GetMergeTagsResponse(projectId: 1, templateSlug: $slug, version: 1, mergeTags: $template['tags'] ?? []));
    }

    $test->templateService->shouldReceive('list')->andReturn(generatedListPage($list));
}

/**
 * Load every generated PHP file in a separate process; null when it all loads.
 */
function generatedLoadError(string $root): ?string
{
    $script = 'require '.var_export(__DIR__.'/../../vendor/autoload.php', true).';';

    foreach (generatedFiles($root) as $file) {
        if (str_ends_with($file, '.php') && ! str_ends_with($file, '.blade.php')) {
            $script .= 'require_once '.var_export($root.'/'.$file, true).';';
        }
    }

    exec(escapeshellarg(PHP_BINARY).' -r '.escapeshellarg($script).' 2>&1', $output, $code);

    return $code === 0 ? null : trim(implode("\n", $output));
}

/**
 * @return array<int, string>
 */
function generatedFiles(string $root): array
{
    if (! is_dir($root)) {
        return [];
    }

    $files = [];

    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS)) as $file) {
        $files[] = Str::after($file->getPathname(), $root.'/');
    }

    sort($files);

    return $files;
}

beforeEach(function () {
    $this->lettrManager = Mockery::mock(LettrManager::class);
    $this->templateService = Mockery::mock(TemplateServiceWrapper::class);
    $this->lettrManager->shouldReceive('templates')->andReturn($this->templateService);
    $this->app->instance(LettrManager::class, $this->lettrManager);

    $id = Str::random(8);
    $this->root = sys_get_temp_dir().'/lettr-generated-'.$id;
    $this->namespace = 'Generated'.$id;

    config()->set('lettr.templates', [
        'html_path' => $this->root.'/templates',
        'blade_path' => resource_path('views/emails/lettr'),
        'mailable_path' => $this->root.'/Mail',
        'mailable_namespace' => $this->namespace.'\\Mail',
        'dto_path' => $this->root.'/Dto',
        'dto_namespace' => $this->namespace.'\\Dto',
        'enum_path' => $this->root.'/Enums',
        'enum_namespace' => $this->namespace.'\\Enums',
        'enum_class' => 'LettrTemplate',
    ]);
});

afterEach(function () {
    exec('rm -rf '.escapeshellarg($this->root));
    exec('rm -rf '.escapeshellarg(resource_path('views/emails/lettr')));
    exec('rm -rf '.escapeshellarg(resource_path('views/mail')));
});

describe('lettr:generate-enum', function () {
    it('generates valid cases for digit-leading and reserved slugs', function () {
        serveTemplates($this, ['2fa-code' => [], 'class' => [], 'new' => [], 'welcome' => []]);

        $this->artisan('lettr:generate-enum')->assertSuccessful();

        expect(file_get_contents($this->root.'/Enums/LettrTemplate.php'))
            ->toContain("case Template2faCode = '2fa-code';")
            ->toContain("case ClassTemplate = 'class';")
            ->toContain("case New = 'new';")
            ->toContain("case Welcome = 'welcome';")
            ->and(generatedLoadError($this->root))->toBeNull();
    });

    it('includes templates from every page', function () {
        $this->templateService->shouldReceive('list')
            ->withArgs(fn ($filter) => $filter->page === 1)->once()
            ->andReturn(generatedListPage([generatedTemplate('first')], page: 1, lastPage: 2));
        $this->templateService->shouldReceive('list')
            ->withArgs(fn ($filter) => $filter->page === 2)->once()
            ->andReturn(generatedListPage([generatedTemplate('second')], page: 2, lastPage: 2));

        $this->artisan('lettr:generate-enum')->assertSuccessful();

        expect(file_get_contents($this->root.'/Enums/LettrTemplate.php'))
            ->toContain("case First = 'first';")
            ->toContain("case Second = 'second';");
    });
});

describe('lettr:generate-dtos', function () {
    it('lets an optional loop tag be left out', function () {
        serveTemplates($this, ['digest' => ['tags' => [
            new MergeTag(key: 'title', required: true),
            new MergeTag(key: 'articles', required: false, children: [new MergeTagChild(key: 'headline')]),
        ]]]);

        $this->artisan('lettr:generate-dtos')->assertSuccessful();

        $class = $this->namespace.'\\Dto\\DigestData';
        $script = 'require '.var_export(__DIR__.'/../../vendor/autoload.php', true).';'
            .'require '.var_export($this->root.'/Dto/DigestDataArticleData.php', true).';'
            .'require '.var_export($this->root.'/Dto/DigestData.php', true).';'
            .'echo json_encode((new '.$class.'(title: "Weekly"))->toArray());';

        exec(escapeshellarg(PHP_BINARY).' -r '.escapeshellarg($script).' 2>&1', $output, $code);

        expect($code)->toBe(0)
            ->and(implode("\n", $output))->toBe('{"title":"Weekly","articles":null}');
    });

    it('keeps raw keys as properties when keys camelCase to the same name', function () {
        serveTemplates($this, ['welcome' => ['tags' => [
            new MergeTag(key: 'FIRST_NAME', required: true),
            new MergeTag(key: 'first_name', required: true),
            new MergeTag(key: 'orderId', required: true),
            new MergeTag(key: 'LAST_NAME', required: true),
        ]]]);

        $this->artisan('lettr:generate-dtos')->assertSuccessful();

        expect(file_get_contents($this->root.'/Dto/WelcomeData.php'))
            ->toContain('public string $FIRST_NAME,')
            ->toContain('public string $first_name,')
            ->toContain('public string $orderId,')
            ->toContain('public string $lastName,')
            ->toContain("'FIRST_NAME' => \$this->FIRST_NAME,")
            ->and(generatedLoadError($this->root))->toBeNull();
    });

    it('numbers item DTOs for loop keys that singularize to the same name', function () {
        serveTemplates($this, ['order' => ['tags' => [
            new MergeTag(key: 'item', required: true, children: [new MergeTagChild(key: 'name')]),
            new MergeTag(key: 'items', required: true, children: [new MergeTagChild(key: 'name')]),
        ]]]);

        $this->artisan('lettr:generate-dtos')->assertSuccessful();

        expect(generatedFiles($this->root))->toBe(['Dto/OrderData.php', 'Dto/OrderDataItem2Data.php', 'Dto/OrderDataItemData.php'])
            ->and(generatedLoadError($this->root))->toBeNull();
    });

    it('fails when --template does not exist', function () {
        serveTemplates($this, ['welcome' => []]);

        $this->artisan('lettr:generate-dtos', ['--template' => 'missing'])->assertFailed();
    });
});

describe('lettr:pull', function () {
    it('generates loadable classes for digit-leading and reserved slugs', function () {
        $tags = [new MergeTag(key: 'code', required: true)];
        serveTemplates($this, ['2fa-code' => ['tags' => $tags], 'new' => ['tags' => $tags]]);

        $this->artisan('lettr:pull', ['--with-mailables' => true])->assertSuccessful();

        expect(generatedFiles($this->root))->toBe([
            'Dto/NewTemplateData.php',
            'Dto/Template2faCodeData.php',
            'Mail/NewTemplate.php',
            'Mail/Template2faCode.php',
        ])->and(generatedLoadError($this->root))->toBeNull();
    });

    it('escapes the subject and leaves it out of API-template Mailables', function () {
        serveTemplates($this, ['dont-miss-out' => ['name' => "Don't miss out"]]);

        $this->artisan('lettr:pull', ['--with-mailables' => true])->assertSuccessful();
        $blade = file_get_contents($this->root.'/Mail/DontMissOut.php');

        $this->artisan('lettr:pull', ['--with-mailables' => true, '--as-html' => true, '--force' => true])->assertSuccessful();
        $api = file_get_contents($this->root.'/Mail/DontMissOut.php');

        expect($blade)->toContain("subject: 'Don\\'t Miss Out',")
            ->and($api)->not->toContain('subject:')
            ->and($api)->toContain("protected ?string \$templateSlug = 'dont-miss-out';")
            ->and(generatedLoadError($this->root))->toBeNull();
    });

    it('derives the view name from a custom blade_path', function () {
        config()->set('lettr.templates.blade_path', resource_path('views/mail'));
        serveTemplates($this, ['welcome' => []]);

        $this->artisan('lettr:pull', ['--with-mailables' => true])->assertSuccessful();

        expect(file_get_contents($this->root.'/Mail/Welcome.php'))
            ->toContain("protected ?string \$bladeView = 'mail.welcome';");
    });

    it('leaves an existing Mailable alone unless --force is given', function () {
        serveTemplates($this, ['welcome' => []]);
        mkdir($this->root.'/Mail', 0755, true);
        file_put_contents($this->root.'/Mail/Welcome.php', '<?php // edited');

        $this->artisan('lettr:pull', ['--with-mailables' => true])
            ->assertSuccessful()
            ->expectsOutputToContain('Mailable already exists');

        expect(file_get_contents($this->root.'/Mail/Welcome.php'))->toBe('<?php // edited');

        $this->artisan('lettr:pull', ['--with-mailables' => true, '--force' => true])->assertSuccessful();

        expect(file_get_contents($this->root.'/Mail/Welcome.php'))->toContain('class Welcome extends LettrMailable');
    });

    it('fails when --template does not exist', function () {
        serveTemplates($this, ['welcome' => []]);

        $this->artisan('lettr:pull', ['--template' => 'missing'])->assertFailed();
    });
});

it('renders a pulled loop with the arrays a DTO produces', function () {
    $blade = (new SparkpostToBladeConverter)->convert('{{#each items}}<li>{{this.name}}: {{this.price.amount}}</li>{{/each}}');

    expect(Blade::render($blade, ['items' => [['name' => 'Pen', 'price' => ['amount' => '2 EUR']]]]))
        ->toBe('<li>Pen: 2 EUR</li>');
});
