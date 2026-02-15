<?php

declare(strict_types=1);

namespace Lettr\Laravel\Console;

use Illuminate\Console\Command;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Str;
use Lettr\Dto\Template\ListTemplatesFilter;
use Lettr\Dto\Template\Template;
use Lettr\Laravel\LettrManager;

use function Laravel\Prompts\progress;

class GenerateEnumCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'lettr:generate-enum
                            {--dry-run : Preview what would be generated without writing files}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Generate a PHP enum from Lettr template slugs';

    public function __construct(
        protected readonly LettrManager $lettr,
        protected readonly Filesystem $files,
    ) {
        parent::__construct();
    }

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $this->components->info('Generating enum from Lettr template slugs...');

        $dryRun = (bool) $this->option('dry-run');

        // Fetch templates
        $templates = $this->fetchTemplates();

        if (empty($templates)) {
            $this->components->warn('No templates found.');

            return self::SUCCESS;
        }

        // Generate enum
        $this->generateEnum($templates, $dryRun);

        return self::SUCCESS;
    }

    /**
     * Fetch templates from the API.
     *
     * @return array<int, Template>
     */
    protected function fetchTemplates(): array
    {
        $response = $this->lettr->templates()->list(new ListTemplatesFilter(perPage: 100));

        return $response->templates->all();
    }

    /**
     * Generate the enum file.
     *
     * @param  array<int, Template>  $templates
     */
    protected function generateEnum(array $templates, bool $dryRun): void
    {
        $enumPath = config('lettr.templates.enum_path');
        $namespace = config('lettr.templates.enum_namespace');
        $className = config('lettr.templates.enum_class');

        $fullPath = $enumPath.'/'.$className.'.php';
        $relativePath = str_replace(base_path().'/', '', $fullPath);

        // Generate enum cases
        $cases = $this->generateCases($templates);

        // Build enum content
        $stub = $this->getStubContent();
        $content = str_replace(
            ['{{ namespace }}', '{{ class }}', '{{ cases }}'],
            [$namespace, $className, $cases],
            $stub
        );

        if (! $dryRun) {
            $this->ensureDirectoryExists($enumPath);
            $this->files->put($fullPath, $content);
        }

        // Output summary
        $this->outputSummary($namespace.'\\'.$className, $relativePath, count($templates), $dryRun);
    }

    /**
     * Generate enum cases from templates.
     *
     * @param  array<int, Template>  $templates
     */
    protected function generateCases(array $templates): string
    {
        $lines = [];

        $progress = progress(
            label: 'Processing templates',
            steps: count($templates),
        );

        $progress->start();

        foreach ($templates as $template) {
            $caseName = $this->slugToCaseName($template->slug);
            $lines[] = "    case {$caseName} = '{$template->slug}';";
            $progress->advance();
        }

        $progress->finish();

        return implode("\n", $lines);
    }

    /**
     * Convert a slug to an enum case name.
     */
    protected function slugToCaseName(string $slug): string
    {
        return Str::studly($slug);
    }

    /**
     * Get the stub file content.
     */
    protected function getStubContent(): string
    {
        $stubPath = __DIR__.'/../../stubs/template-enum.stub';

        return $this->files->get($stubPath);
    }

    /**
     * Ensure a directory exists.
     */
    protected function ensureDirectoryExists(string $path): void
    {
        if (! $this->files->isDirectory($path)) {
            $this->files->makeDirectory($path, 0755, true);
        }
    }

    /**
     * Output the summary of the generated enum.
     */
    protected function outputSummary(string $fullyQualifiedClass, string $relativePath, int $caseCount, bool $dryRun): void
    {
        $this->newLine();

        $prefix = $dryRun ? 'Would generate' : 'Generated';
        $this->components->twoColumnDetail("<fg=gray>{$prefix}:</>");
        $this->components->twoColumnDetail(
            "  <fg=green>✓</> {$fullyQualifiedClass}",
            $relativePath
        );

        $this->newLine();

        $action = $dryRun ? 'Would generate' : 'Generated';
        $this->components->info("Done! {$action} enum with {$caseCount} case(s).");
    }
}
