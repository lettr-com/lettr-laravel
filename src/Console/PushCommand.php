<?php

declare(strict_types=1);

namespace Lettr\Laravel\Console;

use Illuminate\Console\Command;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Str;
use Lettr\Dto\Template\CreatedTemplate;
use Lettr\Dto\Template\CreateTemplateData;
use Lettr\Laravel\Concerns\ThrottlesApiRequests;
use Lettr\Laravel\LettrManager;
use Lettr\Laravel\Support\BladeToSparkpostConverter;

use function Laravel\Prompts\confirm;
use function Laravel\Prompts\progress;
use function Laravel\Prompts\text;

class PushCommand extends Command
{
    use ThrottlesApiRequests;

    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'lettr:push
                            {--path= : Custom path to templates directory}
                            {--template= : Push only a specific template by filename}
                            {--dry-run : Preview what would be created without pushing}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Push local Blade email templates to Lettr API';

    /**
     * @var array<int, array{filename: string, name: string, slug: string}>
     */
    protected array $createdTemplates = [];

    /**
     * @var array<int, array{filename: string, reason: string}>
     */
    protected array $skippedTemplates = [];

    public function __construct(
        protected readonly LettrManager $lettr,
        protected readonly Filesystem $files,
        protected readonly BladeToSparkpostConverter $converter,
    ) {
        parent::__construct();
    }

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $this->components->info('Pushing templates to Lettr...');

        $dryRun = (bool) $this->option('dry-run');

        // Discover or get path
        $path = $this->discoverPath();

        if ($path === null || $path === '') {
            $this->components->error('No template path provided.');

            return self::FAILURE;
        }

        if (! $this->files->isDirectory($path)) {
            $this->components->error("Directory does not exist: {$path}");

            return self::FAILURE;
        }

        // Find blade files
        $bladeFiles = $this->findBladeFiles($path);

        if (empty($bladeFiles)) {
            $this->components->warn('No Blade templates found in the specified directory.');

            return self::SUCCESS;
        }

        // Process templates
        $this->processTemplates($bladeFiles, $dryRun);

        // Output summary
        $this->outputSummary($dryRun);

        return self::SUCCESS;
    }

    /**
     * Discover the path to email templates.
     */
    protected function discoverPath(): ?string
    {
        /** @var string|null $pathOption */
        $pathOption = $this->option('path');

        if ($pathOption !== null) {
            return $pathOption;
        }

        $basePath = resource_path('views');
        $candidates = ['emails', 'mails', 'email', 'mail'];

        foreach ($candidates as $folder) {
            $path = $basePath.'/'.$folder;
            if ($this->files->isDirectory($path)) {
                if (confirm("Found email templates at {$path}. Use this folder?", default: true)) {
                    return $path;
                }
            }
        }

        $customPath = text(
            label: 'Enter path to your email templates:',
            placeholder: 'resources/views/emails',
        );

        if ($customPath === '') {
            return null;
        }

        // Handle relative paths
        if (! str_starts_with($customPath, '/')) {
            return base_path($customPath);
        }

        return $customPath;
    }

    /**
     * Find all Blade files in the given path.
     *
     * @return array<int, string>
     */
    protected function findBladeFiles(string $path): array
    {
        /** @var string|null $templateOption */
        $templateOption = $this->option('template');

        $files = $this->files->glob($path.'/*.blade.php');

        if ($files === false) {
            return [];
        }

        // Filter by template name if specified
        if ($templateOption !== null) {
            $files = array_filter(
                $files,
                fn (string $file): bool => $this->getFilenameWithoutExtension($file) === $templateOption
                    || basename($file) === $templateOption
                    || basename($file) === $templateOption.'.blade.php'
            );
        }

        return array_values($files);
    }

    /**
     * Process all blade files.
     *
     * @param  array<int, string>  $files
     */
    protected function processTemplates(array $files, bool $dryRun): void
    {
        $progress = progress(
            label: 'Processing templates',
            steps: count($files),
        );

        $progress->start();

        foreach ($files as $file) {
            $this->processTemplate($file, $dryRun);
            $progress->advance();
        }

        $progress->finish();
    }

    /**
     * Process a single template file.
     */
    protected function processTemplate(string $filePath, bool $dryRun): void
    {
        $filename = $this->getFilenameWithoutExtension($filePath);
        $name = $this->filenameToName($filename);
        $baseSlug = $this->filenameToSlug($filename);

        // Read file content
        $html = $this->files->get($filePath);

        // Convert Blade syntax to Sparkpost syntax
        $html = $this->converter->convert($html);

        if (empty(trim($html))) {
            $this->skippedTemplates[] = [
                'filename' => basename($filePath),
                'reason' => 'empty content',
            ];

            return;
        }

        // Resolve slug conflicts
        $slug = $dryRun ? $baseSlug : $this->resolveSlug($baseSlug);

        if (! $dryRun) {
            $this->createTemplate($name, $slug, $html);
        }

        $this->createdTemplates[] = [
            'filename' => basename($filePath),
            'name' => $name,
            'slug' => $slug,
            'conflict_resolved' => $slug !== $baseSlug,
        ];
    }

    /**
     * Resolve slug conflicts by appending incrementing numbers.
     */
    protected function resolveSlug(string $baseSlug): string
    {
        $slug = $baseSlug;
        $counter = 1;

        while ($this->withRateLimitRetry(fn () => $this->lettr->templates()->slugExists($slug))) {
            $slug = "{$baseSlug}-{$counter}";
            $counter++;
        }

        return $slug;
    }

    /**
     * Create a template via the API.
     */
    protected function createTemplate(string $name, string $slug, string $html): CreatedTemplate
    {
        $data = new CreateTemplateData(
            name: $name,
            slug: $slug,
            html: $html,
        );

        return $this->withRateLimitRetry(fn () => $this->lettr->templates()->create($data));
    }

    /**
     * Get the filename without the .blade.php extension.
     */
    protected function getFilenameWithoutExtension(string $path): string
    {
        $basename = basename($path);

        return str_replace('.blade.php', '', $basename);
    }

    /**
     * Convert filename to a human-readable name.
     * Example: "welcome-email" -> "Welcome Email"
     */
    protected function filenameToName(string $filename): string
    {
        return Str::headline($filename);
    }

    /**
     * Convert filename to a slug.
     * Example: "WelcomeEmail" -> "welcome-email"
     */
    protected function filenameToSlug(string $filename): string
    {
        return Str::kebab($filename);
    }

    /**
     * Output the summary of created templates.
     */
    protected function outputSummary(bool $dryRun): void
    {
        $this->newLine();

        $prefix = $dryRun ? 'Would create' : 'Created';
        $this->components->twoColumnDetail("<fg=gray>{$prefix}:</>");

        foreach ($this->createdTemplates as $template) {
            $slugDisplay = $template['slug'];
            if (! empty($template['conflict_resolved'])) {
                $slugDisplay .= ' <fg=yellow>(slug conflict resolved)</>';
            }

            $this->components->twoColumnDetail(
                "  <fg=green>✓</> {$template['name']}",
                $slugDisplay
            );
        }

        if (! empty($this->skippedTemplates)) {
            $this->newLine();
            $this->components->twoColumnDetail('<fg=gray>Skipped:</>');

            foreach ($this->skippedTemplates as $template) {
                $this->components->twoColumnDetail(
                    "  <fg=yellow>⊘</> {$template['filename']}",
                    $template['reason']
                );
            }
        }

        $this->newLine();

        $count = count($this->createdTemplates);
        $action = $dryRun ? 'Would create' : 'Created';
        $this->components->info("Done! {$action} {$count} template(s).");
    }
}
