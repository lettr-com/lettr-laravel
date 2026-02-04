<?php

use Illuminate\Filesystem\Filesystem;
use Lettr\Collections\TemplateCollection;
use Lettr\Dto\Template\MergeTag;
use Lettr\Dto\Template\MergeTagChild;
use Lettr\Dto\Template\Template;
use Lettr\Dto\Template\TemplateDetail;
use Lettr\Laravel\Console\GenerateDtosCommand;
use Lettr\Laravel\LettrManager;
use Lettr\Laravel\Services\TemplateServiceWrapper;
use Lettr\Responses\GetMergeTagsResponse;
use Lettr\Responses\ListTemplatesResponse;
use Lettr\Responses\TemplatePagination;
use Lettr\ValueObjects\Timestamp;

function createDtoTemplate(int $id, string $name, string $slug, int $projectId = 1): Template
{
    return new Template(
        id: $id,
        name: $name,
        slug: $slug,
        projectId: $projectId,
        folderId: null,
        createdAt: Timestamp::now(),
        updatedAt: Timestamp::now(),
    );
}

function createDtoTemplateDetail(int $id, string $name, string $slug, ?int $activeVersion = 1, int $projectId = 1): TemplateDetail
{
    return new TemplateDetail(
        id: $id,
        name: $name,
        slug: $slug,
        projectId: $projectId,
        folderId: null,
        activeVersion: $activeVersion,
        versionsCount: 1,
        html: '<html></html>',
        json: null,
        createdAt: Timestamp::now(),
        updatedAt: Timestamp::now(),
    );
}

function createDtoListResponse(array $templates): ListTemplatesResponse
{
    return new ListTemplatesResponse(
        templates: TemplateCollection::from($templates),
        pagination: new TemplatePagination(
            currentPage: 1,
            lastPage: 1,
            perPage: 100,
            total: count($templates),
        ),
    );
}

function createMergeTagsResponse(string $slug, array $mergeTags, int $projectId = 1): GetMergeTagsResponse
{
    return new GetMergeTagsResponse(
        projectId: $projectId,
        templateSlug: $slug,
        version: 1,
        mergeTags: $mergeTags,
    );
}

beforeEach(function () {
    $this->filesystem = Mockery::mock(Filesystem::class);
    $this->lettrManager = Mockery::mock(LettrManager::class);
    $this->templateService = Mockery::mock(TemplateServiceWrapper::class);

    $this->lettrManager->shouldReceive('templates')->andReturn($this->templateService);

    $this->app->instance(LettrManager::class, $this->lettrManager);
    $this->app->instance(Filesystem::class, $this->filesystem);

    config()->set('lettr.templates.dto_path', base_path('app/Dto/Lettr'));
    config()->set('lettr.templates.dto_namespace', 'App\\Dto\\Lettr');
});

it('generates dto from simple merge tags', function () {
    $templates = [createDtoTemplate(1, 'Welcome Email', 'welcome-email')];
    $templateDetail = createDtoTemplateDetail(1, 'Welcome Email', 'welcome-email');
    $mergeTags = [
        new MergeTag(key: 'user_name', required: true, type: 'string'),
        new MergeTag(key: 'order_total', required: false, type: 'number'),
    ];

    $this->templateService
        ->shouldReceive('list')
        ->once()
        ->andReturn(createDtoListResponse($templates));

    $this->templateService
        ->shouldReceive('get')
        ->with('welcome-email', null)
        ->once()
        ->andReturn($templateDetail);

    $this->templateService
        ->shouldReceive('getMergeTags')
        ->with('welcome-email', null, 1)
        ->once()
        ->andReturn(createMergeTagsResponse('welcome-email', $mergeTags));

    $this->filesystem->shouldReceive('isDirectory')->andReturn(true);
    $this->filesystem
        ->shouldReceive('get')
        ->once()
        ->andReturn(file_get_contents(__DIR__.'/../../stubs/template-dto.stub'));

    $this->filesystem
        ->shouldReceive('put')
        ->once()
        ->withArgs(function ($path, $content) {
            return str_contains($path, 'WelcomeEmailData.php')
                && str_contains($content, 'final readonly class WelcomeEmailData')
                && str_contains($content, 'public string $userName,')
                && str_contains($content, 'public ?float $orderTotal = null,')
                && str_contains($content, "'user_name' => \$this->userName,")
                && str_contains($content, "'order_total' => \$this->orderTotal,");
        });

    $this->artisan(GenerateDtosCommand::class)
        ->assertSuccessful();
});

it('generates nested dto for merge tags with children', function () {
    $templates = [createDtoTemplate(1, 'Order Confirmation', 'order-confirmation')];
    $templateDetail = createDtoTemplateDetail(1, 'Order Confirmation', 'order-confirmation');
    $mergeTags = [
        new MergeTag(
            key: 'order',
            required: true,
            type: 'array',
            children: [
                new MergeTagChild(key: 'id', type: 'integer'),
                new MergeTagChild(key: 'total', type: 'number'),
            ]
        ),
    ];

    $this->templateService
        ->shouldReceive('list')
        ->once()
        ->andReturn(createDtoListResponse($templates));

    $this->templateService
        ->shouldReceive('get')
        ->with('order-confirmation', null)
        ->once()
        ->andReturn($templateDetail);

    $this->templateService
        ->shouldReceive('getMergeTags')
        ->with('order-confirmation', null, 1)
        ->once()
        ->andReturn(createMergeTagsResponse('order-confirmation', $mergeTags));

    $this->filesystem->shouldReceive('isDirectory')->andReturn(true);
    $this->filesystem
        ->shouldReceive('get')
        ->andReturn(file_get_contents(__DIR__.'/../../stubs/template-dto.stub'));

    $writtenFiles = [];
    $this->filesystem
        ->shouldReceive('put')
        ->twice()
        ->withArgs(function ($path, $content) use (&$writtenFiles) {
            $writtenFiles[$path] = $content;

            return true;
        });

    $this->artisan(GenerateDtosCommand::class)
        ->assertSuccessful();

    // Verify nested DTO was created (singular: Order not Orders)
    $nestedPath = collect($writtenFiles)->keys()->first(fn ($p) => str_contains($p, 'OrderConfirmationDataOrderData.php'));
    expect($nestedPath)->not->toBeNull();
    expect($writtenFiles[$nestedPath])
        ->toContain('final readonly class OrderConfirmationDataOrderData')
        ->toContain('public ?int $id = null,')
        ->toContain('public ?float $total = null,');

    // Verify main DTO has array type for nested items with typed closure and docblock
    $mainPath = collect($writtenFiles)->keys()->first(fn ($p) => str_ends_with($p, 'OrderConfirmationData.php'));
    expect($mainPath)->not->toBeNull();
    expect($writtenFiles[$mainPath])
        ->toContain('@param OrderConfirmationDataOrderData[]|null $order')
        ->toContain('public array $order,')
        ->toContain("'order' => array_map(fn (OrderConfirmationDataOrderData \$item) => \$item->toArray(), \$this->order),");
});

it('skips templates without merge tags', function () {
    $templates = [
        createDtoTemplate(1, 'Empty Template', 'empty-template'),
        createDtoTemplate(2, 'Valid Template', 'valid-template'),
    ];

    $emptyDetail = createDtoTemplateDetail(1, 'Empty Template', 'empty-template');
    $validDetail = createDtoTemplateDetail(2, 'Valid Template', 'valid-template');

    $validMergeTags = [
        new MergeTag(key: 'name', required: true, type: 'string'),
    ];

    $this->templateService
        ->shouldReceive('list')
        ->once()
        ->andReturn(createDtoListResponse($templates));

    $this->templateService
        ->shouldReceive('get')
        ->with('empty-template', null)
        ->once()
        ->andReturn($emptyDetail);

    $this->templateService
        ->shouldReceive('get')
        ->with('valid-template', null)
        ->once()
        ->andReturn($validDetail);

    $this->templateService
        ->shouldReceive('getMergeTags')
        ->with('empty-template', null, 1)
        ->once()
        ->andReturn(createMergeTagsResponse('empty-template', []));

    $this->templateService
        ->shouldReceive('getMergeTags')
        ->with('valid-template', null, 1)
        ->once()
        ->andReturn(createMergeTagsResponse('valid-template', $validMergeTags));

    $this->filesystem->shouldReceive('isDirectory')->andReturn(true);
    $this->filesystem
        ->shouldReceive('get')
        ->once()
        ->andReturn(file_get_contents(__DIR__.'/../../stubs/template-dto.stub'));

    // Only the valid template DTO should be written
    $this->filesystem
        ->shouldReceive('put')
        ->once()
        ->withArgs(fn ($path) => str_contains($path, 'ValidTemplateData.php'));

    $this->artisan(GenerateDtosCommand::class)
        ->assertSuccessful()
        ->expectsOutputToContain('Skipped (no merge tags)')
        ->expectsOutputToContain('empty-template');
});

it('skips templates without active version', function () {
    $templates = [
        createDtoTemplate(1, 'No Version Template', 'no-version-template'),
        createDtoTemplate(2, 'Valid Template', 'valid-template'),
    ];

    $noVersionDetail = createDtoTemplateDetail(1, 'No Version Template', 'no-version-template', null);
    $validDetail = createDtoTemplateDetail(2, 'Valid Template', 'valid-template', 1);

    $validMergeTags = [
        new MergeTag(key: 'name', required: true, type: 'string'),
    ];

    $this->templateService
        ->shouldReceive('list')
        ->once()
        ->andReturn(createDtoListResponse($templates));

    $this->templateService
        ->shouldReceive('get')
        ->with('no-version-template', null)
        ->once()
        ->andReturn($noVersionDetail);

    $this->templateService
        ->shouldReceive('get')
        ->with('valid-template', null)
        ->once()
        ->andReturn($validDetail);

    // getMergeTags should not be called for no-version-template
    $this->templateService
        ->shouldReceive('getMergeTags')
        ->with('valid-template', null, 1)
        ->once()
        ->andReturn(createMergeTagsResponse('valid-template', $validMergeTags));

    $this->filesystem->shouldReceive('isDirectory')->andReturn(true);
    $this->filesystem
        ->shouldReceive('get')
        ->once()
        ->andReturn(file_get_contents(__DIR__.'/../../stubs/template-dto.stub'));

    $this->filesystem
        ->shouldReceive('put')
        ->once()
        ->withArgs(fn ($path) => str_contains($path, 'ValidTemplateData.php'));

    $this->artisan(GenerateDtosCommand::class)
        ->assertSuccessful();
});

it('does not write files in dry run mode', function () {
    $templates = [createDtoTemplate(1, 'Dry Run Template', 'dry-run-template')];
    $templateDetail = createDtoTemplateDetail(1, 'Dry Run Template', 'dry-run-template');
    $mergeTags = [
        new MergeTag(key: 'name', required: true, type: 'string'),
    ];

    $this->templateService
        ->shouldReceive('list')
        ->once()
        ->andReturn(createDtoListResponse($templates));

    $this->templateService
        ->shouldReceive('get')
        ->with('dry-run-template', null)
        ->once()
        ->andReturn($templateDetail);

    $this->templateService
        ->shouldReceive('getMergeTags')
        ->with('dry-run-template', null, 1)
        ->once()
        ->andReturn(createMergeTagsResponse('dry-run-template', $mergeTags));

    // Stub is still read in dry-run mode
    $this->filesystem->shouldReceive('get')->andReturn(file_get_contents(__DIR__.'/../../stubs/template-dto.stub'));

    // No file write operations should occur
    $this->filesystem->shouldNotReceive('put');
    $this->filesystem->shouldNotReceive('makeDirectory');

    $this->artisan(GenerateDtosCommand::class, ['--dry-run' => true])
        ->assertSuccessful()
        ->expectsOutputToContain('Would generate');
});

it('uses project id from option', function () {
    $templates = [createDtoTemplate(1, 'Project Template', 'project-template', 42)];
    $templateDetail = createDtoTemplateDetail(1, 'Project Template', 'project-template', 1, 42);
    $mergeTags = [
        new MergeTag(key: 'data', required: true, type: 'string'),
    ];

    $this->templateService
        ->shouldReceive('list')
        ->once()
        ->withArgs(fn ($filter) => $filter->projectId === 42)
        ->andReturn(createDtoListResponse($templates));

    $this->templateService
        ->shouldReceive('get')
        ->with('project-template', 42)
        ->once()
        ->andReturn($templateDetail);

    $this->templateService
        ->shouldReceive('getMergeTags')
        ->with('project-template', 42, 1)
        ->once()
        ->andReturn(createMergeTagsResponse('project-template', $mergeTags, 42));

    $this->filesystem->shouldReceive('isDirectory')->andReturn(true);
    $this->filesystem->shouldReceive('get')->andReturn(file_get_contents(__DIR__.'/../../stubs/template-dto.stub'));
    $this->filesystem->shouldReceive('put')->once();

    $this->artisan(GenerateDtosCommand::class, ['--project' => 42])
        ->assertSuccessful();
});

it('uses project id from config when not provided as option', function () {
    config()->set('lettr.default_project_id', 99);

    $templates = [createDtoTemplate(1, 'Config Template', 'config-template', 99)];
    $templateDetail = createDtoTemplateDetail(1, 'Config Template', 'config-template', 1, 99);
    $mergeTags = [
        new MergeTag(key: 'value', required: true, type: 'string'),
    ];

    $this->templateService
        ->shouldReceive('list')
        ->once()
        ->withArgs(fn ($filter) => $filter->projectId === 99)
        ->andReturn(createDtoListResponse($templates));

    $this->templateService
        ->shouldReceive('get')
        ->with('config-template', 99)
        ->once()
        ->andReturn($templateDetail);

    $this->templateService
        ->shouldReceive('getMergeTags')
        ->with('config-template', 99, 1)
        ->once()
        ->andReturn(createMergeTagsResponse('config-template', $mergeTags, 99));

    $this->filesystem->shouldReceive('isDirectory')->andReturn(true);
    $this->filesystem->shouldReceive('get')->andReturn(file_get_contents(__DIR__.'/../../stubs/template-dto.stub'));
    $this->filesystem->shouldReceive('put')->once();

    $this->artisan(GenerateDtosCommand::class)
        ->assertSuccessful();
});

it('filters templates by slug when template option is provided', function () {
    $templates = [
        createDtoTemplate(1, 'First Template', 'first-template'),
        createDtoTemplate(2, 'Second Template', 'second-template'),
    ];

    $templateDetail = createDtoTemplateDetail(2, 'Second Template', 'second-template');
    $mergeTags = [
        new MergeTag(key: 'content', required: true, type: 'string'),
    ];

    $this->templateService
        ->shouldReceive('list')
        ->once()
        ->andReturn(createDtoListResponse($templates));

    // Only the second template should have details fetched
    $this->templateService
        ->shouldReceive('get')
        ->with('second-template', null)
        ->once()
        ->andReturn($templateDetail);

    $this->templateService
        ->shouldReceive('getMergeTags')
        ->with('second-template', null, 1)
        ->once()
        ->andReturn(createMergeTagsResponse('second-template', $mergeTags));

    $this->filesystem->shouldReceive('isDirectory')->andReturn(true);
    $this->filesystem->shouldReceive('get')->andReturn(file_get_contents(__DIR__.'/../../stubs/template-dto.stub'));
    $this->filesystem
        ->shouldReceive('put')
        ->once()
        ->withArgs(fn ($path) => str_contains($path, 'SecondTemplateData.php'));

    $this->artisan(GenerateDtosCommand::class, ['--template' => 'second-template'])
        ->assertSuccessful();
});

it('shows warning when no templates found', function () {
    $this->templateService
        ->shouldReceive('list')
        ->once()
        ->andReturn(createDtoListResponse([]));

    $this->artisan(GenerateDtosCommand::class)
        ->assertSuccessful()
        ->expectsOutputToContain('No templates found');
});

it('creates directories when they do not exist', function () {
    $templates = [createDtoTemplate(1, 'New Dir Template', 'new-dir-template')];
    $templateDetail = createDtoTemplateDetail(1, 'New Dir Template', 'new-dir-template');
    $mergeTags = [
        new MergeTag(key: 'field', required: true, type: 'string'),
    ];

    $this->templateService
        ->shouldReceive('list')
        ->once()
        ->andReturn(createDtoListResponse($templates));

    $this->templateService
        ->shouldReceive('get')
        ->with('new-dir-template', null)
        ->once()
        ->andReturn($templateDetail);

    $this->templateService
        ->shouldReceive('getMergeTags')
        ->with('new-dir-template', null, 1)
        ->once()
        ->andReturn(createMergeTagsResponse('new-dir-template', $mergeTags));

    $this->filesystem->shouldReceive('isDirectory')->andReturn(false);
    $this->filesystem
        ->shouldReceive('makeDirectory')
        ->once()
        ->withArgs(fn ($path, $mode, $recursive) => $mode === 0755 && $recursive === true);

    $this->filesystem->shouldReceive('get')->andReturn(file_get_contents(__DIR__.'/../../stubs/template-dto.stub'));
    $this->filesystem->shouldReceive('put')->once();

    $this->artisan(GenerateDtosCommand::class)
        ->assertSuccessful();
});

it('maps all php types correctly', function () {
    $templates = [createDtoTemplate(1, 'Type Test', 'type-test')];
    $templateDetail = createDtoTemplateDetail(1, 'Type Test', 'type-test');
    $mergeTags = [
        new MergeTag(key: 'string_field', required: true, type: 'string'),
        new MergeTag(key: 'int_field', required: true, type: 'integer'),
        new MergeTag(key: 'float_field', required: true, type: 'number'),
        new MergeTag(key: 'bool_field', required: true, type: 'boolean'),
        new MergeTag(key: 'array_field', required: true, type: 'array'),
        new MergeTag(key: 'unknown_field', required: true, type: 'unknown'),
    ];

    $this->templateService
        ->shouldReceive('list')
        ->once()
        ->andReturn(createDtoListResponse($templates));

    $this->templateService
        ->shouldReceive('get')
        ->with('type-test', null)
        ->once()
        ->andReturn($templateDetail);

    $this->templateService
        ->shouldReceive('getMergeTags')
        ->with('type-test', null, 1)
        ->once()
        ->andReturn(createMergeTagsResponse('type-test', $mergeTags));

    $this->filesystem->shouldReceive('isDirectory')->andReturn(true);
    $this->filesystem->shouldReceive('get')->andReturn(file_get_contents(__DIR__.'/../../stubs/template-dto.stub'));

    $this->filesystem
        ->shouldReceive('put')
        ->once()
        ->withArgs(function ($path, $content) {
            return str_contains($content, 'public string $stringField,')
                && str_contains($content, 'public int $intField,')
                && str_contains($content, 'public float $floatField,')
                && str_contains($content, 'public bool $boolField,')
                && str_contains($content, 'public array $arrayField,')
                && str_contains($content, 'public string $unknownField,');
        });

    $this->artisan(GenerateDtosCommand::class)
        ->assertSuccessful();
});

it('places required properties before optional ones', function () {
    $templates = [createDtoTemplate(1, 'Order Test', 'order-test')];
    $templateDetail = createDtoTemplateDetail(1, 'Order Test', 'order-test');
    $mergeTags = [
        new MergeTag(key: 'optional_first', required: false, type: 'string'),
        new MergeTag(key: 'required_second', required: true, type: 'string'),
        new MergeTag(key: 'optional_third', required: false, type: 'string'),
        new MergeTag(key: 'required_fourth', required: true, type: 'string'),
    ];

    $this->templateService
        ->shouldReceive('list')
        ->once()
        ->andReturn(createDtoListResponse($templates));

    $this->templateService
        ->shouldReceive('get')
        ->with('order-test', null)
        ->once()
        ->andReturn($templateDetail);

    $this->templateService
        ->shouldReceive('getMergeTags')
        ->with('order-test', null, 1)
        ->once()
        ->andReturn(createMergeTagsResponse('order-test', $mergeTags));

    $this->filesystem->shouldReceive('isDirectory')->andReturn(true);
    $this->filesystem->shouldReceive('get')->andReturn(file_get_contents(__DIR__.'/../../stubs/template-dto.stub'));

    $this->filesystem
        ->shouldReceive('put')
        ->once()
        ->withArgs(function ($path, $content) {
            // Check that required fields come before optional fields
            $requiredSecondPos = strpos($content, '$requiredSecond,');
            $requiredFourthPos = strpos($content, '$requiredFourth,');
            $optionalFirstPos = strpos($content, '$optionalFirst = null,');
            $optionalThirdPos = strpos($content, '$optionalThird = null,');

            return $requiredSecondPos < $optionalFirstPos
                && $requiredFourthPos < $optionalFirstPos
                && $requiredSecondPos < $optionalThirdPos
                && $requiredFourthPos < $optionalThirdPos;
        });

    $this->artisan(GenerateDtosCommand::class)
        ->assertSuccessful();
});

it('generates dtos that implement Arrayable interface', function () {
    $templates = [createDtoTemplate(1, 'Arrayable Test', 'arrayable-test')];
    $templateDetail = createDtoTemplateDetail(1, 'Arrayable Test', 'arrayable-test');
    $mergeTags = [
        new MergeTag(key: 'name', required: true, type: 'string'),
        new MergeTag(key: 'age', required: false, type: 'integer'),
    ];

    $this->templateService
        ->shouldReceive('list')
        ->once()
        ->andReturn(createDtoListResponse($templates));

    $this->templateService
        ->shouldReceive('get')
        ->with('arrayable-test', null)
        ->once()
        ->andReturn($templateDetail);

    $this->templateService
        ->shouldReceive('getMergeTags')
        ->with('arrayable-test', null, 1)
        ->once()
        ->andReturn(createMergeTagsResponse('arrayable-test', $mergeTags));

    $this->filesystem->shouldReceive('isDirectory')->andReturn(true);
    $this->filesystem
        ->shouldReceive('get')
        ->once()
        ->andReturn(file_get_contents(__DIR__.'/../../stubs/template-dto.stub'));

    $this->filesystem
        ->shouldReceive('put')
        ->once()
        ->withArgs(function ($path, $content) {
            return str_contains($content, 'use Illuminate\Contracts\Support\Arrayable;')
                && str_contains($content, 'final readonly class ArrayableTestData implements Arrayable')
                && str_contains($content, 'public function toArray(): array');
        });

    $this->artisan(GenerateDtosCommand::class)
        ->assertSuccessful();
});

it('generates nested dtos that also implement Arrayable interface', function () {
    $templates = [createDtoTemplate(1, 'Nested Arrayable', 'nested-arrayable')];
    $templateDetail = createDtoTemplateDetail(1, 'Nested Arrayable', 'nested-arrayable');
    $mergeTags = [
        new MergeTag(
            key: 'items',
            required: true,
            type: 'array',
            children: [
                new MergeTagChild(key: 'product_name', type: 'string'),
                new MergeTagChild(key: 'quantity', type: 'integer'),
            ]
        ),
    ];

    $this->templateService
        ->shouldReceive('list')
        ->once()
        ->andReturn(createDtoListResponse($templates));

    $this->templateService
        ->shouldReceive('get')
        ->with('nested-arrayable', null)
        ->once()
        ->andReturn($templateDetail);

    $this->templateService
        ->shouldReceive('getMergeTags')
        ->with('nested-arrayable', null, 1)
        ->once()
        ->andReturn(createMergeTagsResponse('nested-arrayable', $mergeTags));

    $this->filesystem->shouldReceive('isDirectory')->andReturn(true);
    $this->filesystem
        ->shouldReceive('get')
        ->andReturn(file_get_contents(__DIR__.'/../../stubs/template-dto.stub'));

    $writtenFiles = [];
    $this->filesystem
        ->shouldReceive('put')
        ->twice()
        ->withArgs(function ($path, $content) use (&$writtenFiles) {
            $writtenFiles[$path] = $content;

            return true;
        });

    $this->artisan(GenerateDtosCommand::class)
        ->assertSuccessful();

    // Verify both main and nested DTOs implement Arrayable
    foreach ($writtenFiles as $path => $content) {
        expect($content)
            ->toContain('use Illuminate\Contracts\Support\Arrayable;')
            ->toContain('implements Arrayable')
            ->toContain('public function toArray(): array');
    }
});

it('includes proper imports for nested dto classes', function () {
    $templates = [createDtoTemplate(1, 'Import Test', 'import-test')];
    $templateDetail = createDtoTemplateDetail(1, 'Import Test', 'import-test');
    $mergeTags = [
        new MergeTag(
            key: 'orders',
            required: true,
            type: 'array',
            children: [
                new MergeTagChild(key: 'order_id', type: 'integer'),
            ]
        ),
    ];

    $this->templateService
        ->shouldReceive('list')
        ->once()
        ->andReturn(createDtoListResponse($templates));

    $this->templateService
        ->shouldReceive('get')
        ->with('import-test', null)
        ->once()
        ->andReturn($templateDetail);

    $this->templateService
        ->shouldReceive('getMergeTags')
        ->with('import-test', null, 1)
        ->once()
        ->andReturn(createMergeTagsResponse('import-test', $mergeTags));

    $this->filesystem->shouldReceive('isDirectory')->andReturn(true);
    $this->filesystem
        ->shouldReceive('get')
        ->andReturn(file_get_contents(__DIR__.'/../../stubs/template-dto.stub'));

    $writtenFiles = [];
    $this->filesystem
        ->shouldReceive('put')
        ->twice()
        ->withArgs(function ($path, $content) use (&$writtenFiles) {
            $writtenFiles[$path] = $content;

            return true;
        });

    $this->artisan(GenerateDtosCommand::class)
        ->assertSuccessful();

    // Find the main DTO (not the nested one)
    $mainPath = collect($writtenFiles)->keys()->first(fn ($p) => str_ends_with($p, 'ImportTestData.php') && ! str_contains($p, 'OrderData'));
    expect($mainPath)->not->toBeNull();

    // Verify it imports the nested DTO class
    expect($writtenFiles[$mainPath])
        ->toContain('use App\Dto\Lettr\ImportTestDataOrderData;')
        ->toContain('use Illuminate\Contracts\Support\Arrayable;');
});
