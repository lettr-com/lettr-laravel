<?php

use Illuminate\Filesystem\Filesystem;
use Lettr\Dto\Template\CreatedTemplate;
use Lettr\Dto\Template\CreateTemplateData;
use Lettr\Enums\TemplatePurpose;
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
        ->shouldReceive('create')
        ->once()
        ->withArgs(function (CreateTemplateData $data) {
            return $data->name === 'Welcome Email'
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
        ->shouldReceive('create')
        ->once()
        ->andReturn(createCreatedTemplateForPush(1, 'Order Confirmation', 'order-confirmation'));

    $this->artisan(PushCommand::class)
        ->expectsConfirmation("Found email templates at {$emailsPath}. Use this folder?", 'yes')
        ->assertSuccessful();
});

it('displays server-assigned slug in the output', function () {
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

    // Server may pick any slug (e.g. after a collision it picks welcome-email-42);
    // the command must display whatever the server returns.
    $this->templateService
        ->shouldReceive('create')
        ->once()
        ->andReturn(createCreatedTemplateForPush(1, 'Welcome Email', 'welcome-email-42'));

    $this->artisan(PushCommand::class, ['--path' => $path])
        ->assertSuccessful()
        ->expectsOutputToContain('welcome-email-42');
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
        ->shouldReceive('create')
        ->once()
        ->withArgs(fn (CreateTemplateData $data) => $data->name === 'Second Template')
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

    // Only valid template should be created
    $this->templateService
        ->shouldReceive('create')
        ->once()
        ->withArgs(fn (CreateTemplateData $data) => $data->name === 'Valid Template')
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

it('converts filenames to human-readable names', function () {
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
        ->shouldReceive('create')
        ->once()
        ->withArgs(fn (CreateTemplateData $data) => $data->name === 'My Welcome Email')
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
        ->shouldReceive('create')
        ->times(3)
        ->andReturn(createCreatedTemplateForPush(1, 'Test', 'test'));

    $this->artisan(PushCommand::class, ['--path' => $path])
        ->assertSuccessful()
        ->expectsOutputToContain('Created 3 template(s)');
});

it('creates the templates in the campaign module when purpose is given', function () {
    $path = '/path/to/templates';
    $bladeFile = $path.'/october-newsletter.blade.php';

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
        ->andReturn('<html><body>Hi</body></html>');

    $this->templateService
        ->shouldReceive('create')
        ->once()
        ->withArgs(fn (CreateTemplateData $data): bool => $data->purpose === TemplatePurpose::Campaign
            && $data->toArray()['purpose'] === 'campaign')
        ->andReturn(createCreatedTemplateForPush(1, 'October Newsletter', 'october-newsletter'));

    $this->artisan(PushCommand::class, ['--path' => $path, '--purpose' => 'campaign'])
        ->assertSuccessful()
        ->expectsOutputToContain('as campaign');
});

it('sends no purpose key when the option is omitted', function () {
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
        ->shouldReceive('create')
        ->once()
        ->withArgs(fn (CreateTemplateData $data): bool => $data->purpose === null
            && ! array_key_exists('purpose', $data->toArray()))
        ->andReturn(createCreatedTemplateForPush(1, 'Welcome Email', 'welcome-email'));

    $this->artisan(PushCommand::class, ['--path' => $path])
        ->assertSuccessful();
});

it('fails before touching the filesystem when the purpose is not a module', function () {
    $this->filesystem->shouldNotReceive('isDirectory');
    $this->templateService->shouldNotReceive('create');

    $this->artisan(PushCommand::class, ['--path' => '/path/to/templates', '--purpose' => 'newsletter'])
        ->assertFailed()
        ->expectsOutputToContain("Invalid --purpose 'newsletter'");
});
