<?php

use Illuminate\Filesystem\Filesystem;
use Lettr\Dto\Template\CreatedTemplate;
use Lettr\Dto\Template\CreateTemplateData;
use Lettr\Laravel\Console\PushCommand;
use Lettr\Laravel\LettrManager;
use Lettr\Laravel\Services\TemplateServiceWrapper;
use Lettr\ValueObjects\Timestamp;

function createCreatedTemplateForPush(int $id, string $name, string $slug, int $projectId = 1): CreatedTemplate
{
    return new CreatedTemplate(
        id: $id,
        name: $name,
        slug: $slug,
        projectId: $projectId,
        folderId: 1,
        activeVersion: 1,
        mergeTags: [],
        createdAt: Timestamp::now(),
    );
}

beforeEach(function () {
    $this->filesystem = Mockery::mock(Filesystem::class);
    $this->lettrManager = Mockery::mock(LettrManager::class);
    $this->templateService = Mockery::mock(TemplateServiceWrapper::class);

    $this->lettrManager->shouldReceive('templates')->andReturn($this->templateService);

    $this->app->instance(LettrManager::class, $this->lettrManager);
    $this->app->instance(Filesystem::class, $this->filesystem);

});

it('pushes templates from specified path', function () {
    $path = '/path/to/templates';
    $bladeFile = $path.'/welcome-email.blade.php';

    $this->filesystem
        ->shouldReceive('isDirectory')
        ->with($path)
        ->andReturn(true);

    $this->filesystem
        ->shouldReceive('glob')
        ->with($path.'/*.blade.php')
        ->andReturn([$bladeFile]);

    $this->filesystem
        ->shouldReceive('get')
        ->with($bladeFile)
        ->andReturn('<html><body>Welcome!</body></html>');

    $this->templateService
        ->shouldReceive('slugExists')
        ->with('welcome-email')
        ->once()
        ->andReturn(false);

    $this->templateService
        ->shouldReceive('create')
        ->once()
        ->withArgs(function (CreateTemplateData $data) {
            return $data->name === 'Welcome Email'
                && $data->slug === 'welcome-email'
                && $data->html === '<html><body>Welcome!</body></html>';
        })
        ->andReturn(createCreatedTemplateForPush(1, 'Welcome Email', 'welcome-email'));

    $this->artisan(PushCommand::class, ['--path' => $path])
        ->assertSuccessful()
        ->expectsOutputToContain('Welcome Email');
});

it('auto-discovers emails folder and confirms with user', function () {
    $basePath = resource_path('views');
    $emailsPath = $basePath.'/emails';
    $bladeFile = $emailsPath.'/order-confirmation.blade.php';

    // First check for 'emails' folder
    $this->filesystem
        ->shouldReceive('isDirectory')
        ->with($emailsPath)
        ->andReturn(true);

    // Second check after path discovery
    $this->filesystem
        ->shouldReceive('isDirectory')
        ->with($emailsPath)
        ->andReturn(true);

    $this->filesystem
        ->shouldReceive('glob')
        ->with($emailsPath.'/*.blade.php')
        ->andReturn([$bladeFile]);

    $this->filesystem
        ->shouldReceive('get')
        ->with($bladeFile)
        ->andReturn('<html>Order Confirmation</html>');

    $this->templateService
        ->shouldReceive('slugExists')
        ->with('order-confirmation')
        ->andReturn(false);

    $this->templateService
        ->shouldReceive('create')
        ->once()
        ->andReturn(createCreatedTemplateForPush(1, 'Order Confirmation', 'order-confirmation'));

    $this->artisan(PushCommand::class)
        ->expectsConfirmation("Found email templates at {$emailsPath}. Use this folder?", 'yes')
        ->assertSuccessful();
});

it('resolves slug conflicts by appending numbers', function () {
    $path = '/path/to/templates';
    $bladeFile = $path.'/welcome-email.blade.php';

    $this->filesystem
        ->shouldReceive('isDirectory')
        ->with($path)
        ->andReturn(true);

    $this->filesystem
        ->shouldReceive('glob')
        ->with($path.'/*.blade.php')
        ->andReturn([$bladeFile]);

    $this->filesystem
        ->shouldReceive('get')
        ->with($bladeFile)
        ->andReturn('<html>Welcome!</html>');

    // First slug exists
    $this->templateService
        ->shouldReceive('slugExists')
        ->with('welcome-email')
        ->once()
        ->andReturn(true);

    // Second slug also exists
    $this->templateService
        ->shouldReceive('slugExists')
        ->with('welcome-email-1')
        ->once()
        ->andReturn(true);

    // Third slug is available
    $this->templateService
        ->shouldReceive('slugExists')
        ->with('welcome-email-2')
        ->once()
        ->andReturn(false);

    $this->templateService
        ->shouldReceive('create')
        ->once()
        ->withArgs(fn (CreateTemplateData $data) => $data->slug === 'welcome-email-2')
        ->andReturn(createCreatedTemplateForPush(1, 'Welcome Email', 'welcome-email-2'));

    $this->artisan(PushCommand::class, ['--path' => $path])
        ->assertSuccessful()
        ->expectsOutputToContain('welcome-email-2');
});

it('filters templates by filename when template option is provided', function () {
    $path = '/path/to/templates';
    $files = [
        $path.'/first-template.blade.php',
        $path.'/second-template.blade.php',
    ];

    $this->filesystem
        ->shouldReceive('isDirectory')
        ->with($path)
        ->andReturn(true);

    $this->filesystem
        ->shouldReceive('glob')
        ->with($path.'/*.blade.php')
        ->andReturn($files);

    // Only the second template should be processed
    $this->filesystem
        ->shouldReceive('get')
        ->with($path.'/second-template.blade.php')
        ->once()
        ->andReturn('<html>Second</html>');

    $this->templateService
        ->shouldReceive('slugExists')
        ->with('second-template')
        ->andReturn(false);

    $this->templateService
        ->shouldReceive('create')
        ->once()
        ->withArgs(fn (CreateTemplateData $data) => $data->slug === 'second-template')
        ->andReturn(createCreatedTemplateForPush(2, 'Second Template', 'second-template'));

    $this->artisan(PushCommand::class, ['--path' => $path, '--template' => 'second-template'])
        ->assertSuccessful();
});

it('shows warning when no templates found', function () {
    $path = '/path/to/empty';

    $this->filesystem
        ->shouldReceive('isDirectory')
        ->with($path)
        ->andReturn(true);

    $this->filesystem
        ->shouldReceive('glob')
        ->with($path.'/*.blade.php')
        ->andReturn([]);

    $this->artisan(PushCommand::class, ['--path' => $path])
        ->assertSuccessful()
        ->expectsOutputToContain('No Blade templates found');
});

it('does not create templates in dry run mode', function () {
    $path = '/path/to/templates';
    $bladeFile = $path.'/dry-run-template.blade.php';

    $this->filesystem
        ->shouldReceive('isDirectory')
        ->with($path)
        ->andReturn(true);

    $this->filesystem
        ->shouldReceive('glob')
        ->with($path.'/*.blade.php')
        ->andReturn([$bladeFile]);

    $this->filesystem
        ->shouldReceive('get')
        ->with($bladeFile)
        ->andReturn('<html>Dry Run Content</html>');

    // No API calls should be made
    $this->templateService->shouldNotReceive('slugExists');
    $this->templateService->shouldNotReceive('create');

    $this->artisan(PushCommand::class, ['--path' => $path, '--dry-run' => true])
        ->assertSuccessful()
        ->expectsOutputToContain('Would create')
        ->expectsOutputToContain('Dry Run Template');
});

it('skips templates with empty content', function () {
    $path = '/path/to/templates';
    $files = [
        $path.'/empty-template.blade.php',
        $path.'/valid-template.blade.php',
    ];

    $this->filesystem
        ->shouldReceive('isDirectory')
        ->with($path)
        ->andReturn(true);

    $this->filesystem
        ->shouldReceive('glob')
        ->with($path.'/*.blade.php')
        ->andReturn($files);

    $this->filesystem
        ->shouldReceive('get')
        ->with($path.'/empty-template.blade.php')
        ->andReturn('   ');

    $this->filesystem
        ->shouldReceive('get')
        ->with($path.'/valid-template.blade.php')
        ->andReturn('<html>Valid</html>');

    $this->templateService
        ->shouldReceive('slugExists')
        ->with('valid-template')
        ->andReturn(false);

    // Only valid template should be created
    $this->templateService
        ->shouldReceive('create')
        ->once()
        ->withArgs(fn (CreateTemplateData $data) => $data->slug === 'valid-template')
        ->andReturn(createCreatedTemplateForPush(1, 'Valid Template', 'valid-template'));

    $this->artisan(PushCommand::class, ['--path' => $path])
        ->assertSuccessful()
        ->expectsOutputToContain('Skipped')
        ->expectsOutputToContain('empty-template.blade.php');
});

it('shows error when directory does not exist', function () {
    $path = '/nonexistent/path';

    $this->filesystem
        ->shouldReceive('isDirectory')
        ->with($path)
        ->andReturn(false);

    $this->artisan(PushCommand::class, ['--path' => $path])
        ->assertFailed()
        ->expectsOutputToContain('Directory does not exist');
});

it('converts filenames to proper names and slugs', function () {
    $path = '/path/to/templates';
    $bladeFile = $path.'/MyWelcomeEmail.blade.php';

    $this->filesystem
        ->shouldReceive('isDirectory')
        ->with($path)
        ->andReturn(true);

    $this->filesystem
        ->shouldReceive('glob')
        ->with($path.'/*.blade.php')
        ->andReturn([$bladeFile]);

    $this->filesystem
        ->shouldReceive('get')
        ->with($bladeFile)
        ->andReturn('<html>Welcome!</html>');

    $this->templateService
        ->shouldReceive('slugExists')
        ->with('my-welcome-email')
        ->andReturn(false);

    $this->templateService
        ->shouldReceive('create')
        ->once()
        ->withArgs(function (CreateTemplateData $data) {
            return $data->name === 'My Welcome Email'
                && $data->slug === 'my-welcome-email';
        })
        ->andReturn(createCreatedTemplateForPush(1, 'My Welcome Email', 'my-welcome-email'));

    $this->artisan(PushCommand::class, ['--path' => $path])
        ->assertSuccessful();
});

it('pushes multiple templates', function () {
    $path = '/path/to/templates';
    $files = [
        $path.'/welcome.blade.php',
        $path.'/order-confirmation.blade.php',
        $path.'/password-reset.blade.php',
    ];

    $this->filesystem
        ->shouldReceive('isDirectory')
        ->with($path)
        ->andReturn(true);

    $this->filesystem
        ->shouldReceive('glob')
        ->with($path.'/*.blade.php')
        ->andReturn($files);

    foreach ($files as $file) {
        $this->filesystem
            ->shouldReceive('get')
            ->with($file)
            ->once()
            ->andReturn('<html>Content</html>');
    }

    $this->templateService
        ->shouldReceive('slugExists')
        ->times(3)
        ->andReturn(false);

    $this->templateService
        ->shouldReceive('create')
        ->times(3)
        ->andReturn(createCreatedTemplateForPush(1, 'Test', 'test'));

    $this->artisan(PushCommand::class, ['--path' => $path])
        ->assertSuccessful()
        ->expectsOutputToContain('Created 3 template(s)');
});
