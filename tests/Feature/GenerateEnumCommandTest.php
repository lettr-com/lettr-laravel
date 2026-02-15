<?php

use Illuminate\Filesystem\Filesystem;
use Lettr\Collections\TemplateCollection;
use Lettr\Dto\Template\Template;
use Lettr\Laravel\Console\GenerateEnumCommand;
use Lettr\Laravel\LettrManager;
use Lettr\Laravel\Services\TemplateServiceWrapper;
use Lettr\Responses\ListTemplatesResponse;
use Lettr\Responses\TemplatePagination;
use Lettr\ValueObjects\Timestamp;

function createEnumTemplate(int $id, string $name, string $slug, int $projectId = 1): Template
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

function createEnumListResponse(array $templates): ListTemplatesResponse
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

beforeEach(function () {
    $this->filesystem = Mockery::mock(Filesystem::class);
    $this->lettrManager = Mockery::mock(LettrManager::class);
    $this->templateService = Mockery::mock(TemplateServiceWrapper::class);

    $this->lettrManager->shouldReceive('templates')->andReturn($this->templateService);

    $this->app->instance(LettrManager::class, $this->lettrManager);
    $this->app->instance(Filesystem::class, $this->filesystem);

    config()->set('lettr.templates.enum_path', base_path('app/Enums'));
    config()->set('lettr.templates.enum_namespace', 'App\\Enums');
    config()->set('lettr.templates.enum_class', 'LettrTemplate');
});

it('generates enum from template slugs', function () {
    $templates = [
        createEnumTemplate(1, 'Welcome Email', 'welcome-email'),
        createEnumTemplate(2, 'Order Confirmation', 'order-confirmation'),
        createEnumTemplate(3, 'Expired Card', 'expired-card'),
    ];

    $this->templateService
        ->shouldReceive('list')
        ->once()
        ->andReturn(createEnumListResponse($templates));

    $this->filesystem->shouldReceive('isDirectory')->andReturn(true);
    $this->filesystem
        ->shouldReceive('get')
        ->once()
        ->andReturn(file_get_contents(__DIR__.'/../../stubs/template-enum.stub'));

    $this->filesystem
        ->shouldReceive('put')
        ->once()
        ->withArgs(function ($path, $content) {
            return str_contains($path, 'LettrTemplate.php')
                && str_contains($content, 'enum LettrTemplate: string')
                && str_contains($content, "case WelcomeEmail = 'welcome-email';")
                && str_contains($content, "case OrderConfirmation = 'order-confirmation';")
                && str_contains($content, "case ExpiredCard = 'expired-card';");
        });

    $this->artisan(GenerateEnumCommand::class)
        ->assertSuccessful();
});

it('converts slugs to studly case correctly', function () {
    $templates = [
        createEnumTemplate(1, 'Simple', 'simple'),
        createEnumTemplate(2, 'Two Words', 'two-words'),
        createEnumTemplate(3, 'Three Word Slug', 'three-word-slug'),
        createEnumTemplate(4, 'With Numbers 123', 'with-numbers-123'),
    ];

    $this->templateService
        ->shouldReceive('list')
        ->once()
        ->andReturn(createEnumListResponse($templates));

    $this->filesystem->shouldReceive('isDirectory')->andReturn(true);
    $this->filesystem
        ->shouldReceive('get')
        ->once()
        ->andReturn(file_get_contents(__DIR__.'/../../stubs/template-enum.stub'));

    $this->filesystem
        ->shouldReceive('put')
        ->once()
        ->withArgs(function ($path, $content) {
            return str_contains($content, "case Simple = 'simple';")
                && str_contains($content, "case TwoWords = 'two-words';")
                && str_contains($content, "case ThreeWordSlug = 'three-word-slug';")
                && str_contains($content, "case WithNumbers123 = 'with-numbers-123';");
        });

    $this->artisan(GenerateEnumCommand::class)
        ->assertSuccessful();
});

it('does not write files in dry run mode', function () {
    $templates = [
        createEnumTemplate(1, 'Test Template', 'test-template'),
    ];

    $this->templateService
        ->shouldReceive('list')
        ->once()
        ->andReturn(createEnumListResponse($templates));

    $this->filesystem
        ->shouldReceive('get')
        ->andReturn(file_get_contents(__DIR__.'/../../stubs/template-enum.stub'));

    // No file write operations should occur
    $this->filesystem->shouldNotReceive('put');
    $this->filesystem->shouldNotReceive('makeDirectory');

    $this->artisan(GenerateEnumCommand::class, ['--dry-run' => true])
        ->assertSuccessful()
        ->expectsOutputToContain('Would generate');
});

it('shows warning when no templates found', function () {
    $this->templateService
        ->shouldReceive('list')
        ->once()
        ->andReturn(createEnumListResponse([]));

    $this->artisan(GenerateEnumCommand::class)
        ->assertSuccessful()
        ->expectsOutputToContain('No templates found');
});

it('creates directories when they do not exist', function () {
    $templates = [
        createEnumTemplate(1, 'New Dir Template', 'new-dir-template'),
    ];

    $this->templateService
        ->shouldReceive('list')
        ->once()
        ->andReturn(createEnumListResponse($templates));

    $this->filesystem->shouldReceive('isDirectory')->andReturn(false);
    $this->filesystem
        ->shouldReceive('makeDirectory')
        ->once()
        ->withArgs(fn ($path, $mode, $recursive) => $mode === 0755 && $recursive === true);

    $this->filesystem->shouldReceive('get')->andReturn(file_get_contents(__DIR__.'/../../stubs/template-enum.stub'));
    $this->filesystem->shouldReceive('put')->once();

    $this->artisan(GenerateEnumCommand::class)
        ->assertSuccessful();
});

it('uses custom enum class name from config', function () {
    config()->set('lettr.templates.enum_class', 'CustomTemplateEnum');
    config()->set('lettr.templates.enum_namespace', 'App\\Custom\\Enums');

    $templates = [
        createEnumTemplate(1, 'Test Template', 'test-template'),
    ];

    $this->templateService
        ->shouldReceive('list')
        ->once()
        ->andReturn(createEnumListResponse($templates));

    $this->filesystem->shouldReceive('isDirectory')->andReturn(true);
    $this->filesystem->shouldReceive('get')->andReturn(file_get_contents(__DIR__.'/../../stubs/template-enum.stub'));

    $this->filesystem
        ->shouldReceive('put')
        ->once()
        ->withArgs(function ($path, $content) {
            return str_contains($path, 'CustomTemplateEnum.php')
                && str_contains($content, 'namespace App\\Custom\\Enums;')
                && str_contains($content, 'enum CustomTemplateEnum: string');
        });

    $this->artisan(GenerateEnumCommand::class)
        ->assertSuccessful();
});

it('outputs correct file path in summary', function () {
    $templates = [
        createEnumTemplate(1, 'Test Template', 'test-template'),
    ];

    $this->templateService
        ->shouldReceive('list')
        ->once()
        ->andReturn(createEnumListResponse($templates));

    $this->filesystem->shouldReceive('isDirectory')->andReturn(true);
    $this->filesystem->shouldReceive('get')->andReturn(file_get_contents(__DIR__.'/../../stubs/template-enum.stub'));
    $this->filesystem->shouldReceive('put')->once();

    $this->artisan(GenerateEnumCommand::class)
        ->assertSuccessful()
        ->expectsOutputToContain('App\\Enums\\LettrTemplate')
        ->expectsOutputToContain('enum with 1 case(s)');
});
