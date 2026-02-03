<?php

use Illuminate\Filesystem\Filesystem;
use Lettr\Collections\TemplateCollection;
use Lettr\Dto\Template\MergeTag;
use Lettr\Dto\Template\Template;
use Lettr\Dto\Template\TemplateDetail;
use Lettr\Laravel\Console\PullCommand;
use Lettr\Laravel\LettrManager;
use Lettr\Laravel\Services\TemplateServiceWrapper;
use Lettr\Responses\GetMergeTagsResponse;
use Lettr\Responses\ListTemplatesResponse;
use Lettr\Responses\TemplatePagination;
use Lettr\ValueObjects\Timestamp;

function createTemplate(int $id, string $name, string $slug, int $projectId = 1): Template
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

function createTemplateDetail(int $id, string $name, string $slug, ?string $html, int $projectId = 1): TemplateDetail
{
    return new TemplateDetail(
        id: $id,
        name: $name,
        slug: $slug,
        projectId: $projectId,
        folderId: null,
        activeVersion: 1,
        versionsCount: 1,
        html: $html,
        json: null,
        createdAt: Timestamp::now(),
        updatedAt: Timestamp::now(),
    );
}

function createListResponse(array $templates): ListTemplatesResponse
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

    config()->set('lettr.templates.html_path', base_path('resources/templates/lettr'));
    config()->set('lettr.templates.blade_path', base_path('resources/views/emails/lettr'));
    config()->set('lettr.templates.mailable_path', base_path('app/Mail/Lettr'));
    config()->set('lettr.templates.mailable_namespace', 'App\\Mail\\Lettr');
    config()->set('lettr.templates.dto_path', base_path('app/DataTransferObjects/Lettr'));
    config()->set('lettr.templates.dto_namespace', 'App\\DataTransferObjects\\Lettr');
});

it('pulls templates and saves them as blade files by default', function () {
    $templates = [createTemplate(1, 'Welcome Email', 'welcome-email')];
    $templateDetail = createTemplateDetail(1, 'Welcome Email', 'welcome-email', '<html><body>Welcome!</body></html>');

    $this->templateService
        ->shouldReceive('list')
        ->once()
        ->andReturn(createListResponse($templates));

    $this->templateService
        ->shouldReceive('get')
        ->with('welcome-email', null)
        ->once()
        ->andReturn($templateDetail);

    $this->filesystem->shouldReceive('isDirectory')->andReturn(true);
    $this->filesystem
        ->shouldReceive('put')
        ->once()
        ->withArgs(function ($path, $content) {
            return str_contains($path, 'welcome-email.blade.php')
                && $content === '<html><body>Welcome!</body></html>';
        });

    $this->artisan(PullCommand::class)
        ->assertSuccessful();
});

it('pulls templates and saves them as html files with --as-html flag', function () {
    $templates = [createTemplate(1, 'Welcome Email', 'welcome-email')];
    $templateDetail = createTemplateDetail(1, 'Welcome Email', 'welcome-email', '<html><body>Welcome!</body></html>');

    $this->templateService
        ->shouldReceive('list')
        ->once()
        ->andReturn(createListResponse($templates));

    $this->templateService
        ->shouldReceive('get')
        ->with('welcome-email', null)
        ->once()
        ->andReturn($templateDetail);

    $this->filesystem->shouldReceive('isDirectory')->andReturn(true);
    $this->filesystem
        ->shouldReceive('put')
        ->once()
        ->withArgs(function ($path, $content) {
            return str_contains($path, 'welcome-email.html')
                && $content === '<html><body>Welcome!</body></html>';
        });

    $this->artisan(PullCommand::class, ['--as-html' => true])
        ->assertSuccessful();
});

it('skips templates without html content', function () {
    $templates = [
        createTemplate(1, 'Empty Template', 'empty-template'),
        createTemplate(2, 'Valid Template', 'valid-template'),
    ];

    $emptyDetail = createTemplateDetail(1, 'Empty Template', 'empty-template', '');
    $validDetail = createTemplateDetail(2, 'Valid Template', 'valid-template', '<html><body>Content</body></html>');

    $this->templateService
        ->shouldReceive('list')
        ->once()
        ->andReturn(createListResponse($templates));

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

    $this->filesystem->shouldReceive('isDirectory')->andReturn(true);

    // Only the valid template should be saved
    $this->filesystem
        ->shouldReceive('put')
        ->once()
        ->withArgs(fn ($path, $content) => str_contains($path, 'valid-template.blade.php'));

    $this->artisan(PullCommand::class)
        ->assertSuccessful()
        ->expectsOutputToContain('Skipped (no HTML)')
        ->expectsOutputToContain('empty-template');
});

it('skips templates with null html content', function () {
    $templates = [createTemplate(1, 'Null HTML Template', 'null-html-template')];
    $nullHtmlDetail = createTemplateDetail(1, 'Null HTML Template', 'null-html-template', null);

    $this->templateService
        ->shouldReceive('list')
        ->once()
        ->andReturn(createListResponse($templates));

    $this->templateService
        ->shouldReceive('get')
        ->with('null-html-template', null)
        ->once()
        ->andReturn($nullHtmlDetail);

    // No file should be written
    $this->filesystem->shouldNotReceive('put');

    $this->artisan(PullCommand::class)
        ->assertSuccessful()
        ->expectsOutputToContain('Skipped (no HTML)');
});

it('uses project id from option', function () {
    $templates = [createTemplate(1, 'Project Template', 'project-template', 42)];
    $templateDetail = createTemplateDetail(1, 'Project Template', 'project-template', '<html>Content</html>', 42);

    $this->templateService
        ->shouldReceive('list')
        ->once()
        ->withArgs(fn ($filter) => $filter->projectId === 42)
        ->andReturn(createListResponse($templates));

    $this->templateService
        ->shouldReceive('get')
        ->with('project-template', 42)
        ->once()
        ->andReturn($templateDetail);

    $this->filesystem->shouldReceive('isDirectory')->andReturn(true);
    $this->filesystem->shouldReceive('put')->once();

    $this->artisan(PullCommand::class, ['--project' => 42])
        ->assertSuccessful();
});

it('uses project id from config when not provided as option', function () {
    config()->set('lettr.default_project_id', 99);

    $templates = [createTemplate(1, 'Config Template', 'config-template', 99)];
    $templateDetail = createTemplateDetail(1, 'Config Template', 'config-template', '<html>Content</html>', 99);

    $this->templateService
        ->shouldReceive('list')
        ->once()
        ->withArgs(fn ($filter) => $filter->projectId === 99)
        ->andReturn(createListResponse($templates));

    $this->templateService
        ->shouldReceive('get')
        ->with('config-template', 99)
        ->once()
        ->andReturn($templateDetail);

    $this->filesystem->shouldReceive('isDirectory')->andReturn(true);
    $this->filesystem->shouldReceive('put')->once();

    $this->artisan(PullCommand::class)
        ->assertSuccessful();
});

it('filters templates by slug when template option is provided', function () {
    $templates = [
        createTemplate(1, 'First Template', 'first-template'),
        createTemplate(2, 'Second Template', 'second-template'),
    ];

    $templateDetail = createTemplateDetail(2, 'Second Template', 'second-template', '<html>Second</html>');

    $this->templateService
        ->shouldReceive('list')
        ->once()
        ->andReturn(createListResponse($templates));

    // Only the second template should be fetched
    $this->templateService
        ->shouldReceive('get')
        ->with('second-template', null)
        ->once()
        ->andReturn($templateDetail);

    $this->filesystem->shouldReceive('isDirectory')->andReturn(true);
    $this->filesystem
        ->shouldReceive('put')
        ->once()
        ->withArgs(fn ($path) => str_contains($path, 'second-template.blade.php'));

    $this->artisan(PullCommand::class, ['--template' => 'second-template'])
        ->assertSuccessful();
});

it('shows warning when no templates found', function () {
    $this->templateService
        ->shouldReceive('list')
        ->once()
        ->andReturn(createListResponse([]));

    $this->artisan(PullCommand::class)
        ->assertSuccessful()
        ->expectsOutputToContain('No templates found');
});

it('does not write files in dry run mode', function () {
    $templates = [createTemplate(1, 'Dry Run Template', 'dry-run-template')];
    $templateDetail = createTemplateDetail(1, 'Dry Run Template', 'dry-run-template', '<html>Content</html>');

    $this->templateService
        ->shouldReceive('list')
        ->once()
        ->andReturn(createListResponse($templates));

    $this->templateService
        ->shouldReceive('get')
        ->once()
        ->andReturn($templateDetail);

    // No file operations should occur
    $this->filesystem->shouldNotReceive('put');
    $this->filesystem->shouldNotReceive('makeDirectory');

    $this->artisan(PullCommand::class, ['--dry-run' => true])
        ->assertSuccessful()
        ->expectsOutputToContain('Would download');
});

it('generates mailable classes when with-mailables option is provided', function () {
    $templates = [createTemplate(1, 'Mailable Template', 'mailable-template')];
    $templateDetail = createTemplateDetail(1, 'Mailable Template', 'mailable-template', '<html>Content</html>');

    $mergeTags = [
        new MergeTag(key: 'name', type: 'string', required: true, children: null),
    ];

    $this->templateService
        ->shouldReceive('list')
        ->once()
        ->andReturn(createListResponse($templates));

    $this->templateService
        ->shouldReceive('get')
        ->once()
        ->andReturn($templateDetail);

    $this->templateService
        ->shouldReceive('getMergeTags')
        ->with('mailable-template', null, 1)
        ->once()
        ->andReturn(new GetMergeTagsResponse(
            projectId: 1,
            templateSlug: 'mailable-template',
            version: 1,
            mergeTags: $mergeTags,
        ));

    $this->filesystem->shouldReceive('isDirectory')->andReturn(true);

    // Should write blade, DTO, and mailable
    $this->filesystem->shouldReceive('put')->times(3);

    $this->filesystem
        ->shouldReceive('get')
        ->with(Mockery::pattern('/blade-mailable\.stub$/'))
        ->andReturn(file_get_contents(__DIR__.'/../../stubs/blade-mailable.stub'));

    $this->filesystem
        ->shouldReceive('get')
        ->with(Mockery::pattern('/template-dto\.stub$/'))
        ->andReturn(file_get_contents(__DIR__.'/../../stubs/template-dto.stub'));

    $this->artisan(PullCommand::class, ['--with-mailables' => true])
        ->assertSuccessful()
        ->expectsOutputToContain('Generated DTOs')
        ->expectsOutputToContain('Generated Mailables');
});

it('creates directories when they do not exist', function () {
    $templates = [createTemplate(1, 'New Directory Template', 'new-directory-template')];
    $templateDetail = createTemplateDetail(1, 'New Directory Template', 'new-directory-template', '<html>Content</html>');

    $this->templateService
        ->shouldReceive('list')
        ->once()
        ->andReturn(createListResponse($templates));

    $this->templateService
        ->shouldReceive('get')
        ->once()
        ->andReturn($templateDetail);

    $this->filesystem->shouldReceive('isDirectory')->andReturn(false);
    $this->filesystem
        ->shouldReceive('makeDirectory')
        ->once()
        ->withArgs(fn ($path, $mode, $recursive) => $mode === 0755 && $recursive === true);

    $this->filesystem->shouldReceive('put')->once();

    $this->artisan(PullCommand::class)
        ->assertSuccessful();
});
