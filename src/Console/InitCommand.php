<?php

declare(strict_types=1);

namespace Lettr\Laravel\Console;

use GuzzleHttp\Promise\PromiseInterface;
use Illuminate\Console\Command;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Lettr\Laravel\Concerns\DisplayHelper;
use Lettr\Laravel\LettrManager;

use function Laravel\Prompts\confirm;
use function Laravel\Prompts\multiselect;
use function Laravel\Prompts\note;
use function Laravel\Prompts\password;
use function Laravel\Prompts\select;
use function Laravel\Prompts\text;

class InitCommand extends Command
{
    use DisplayHelper;

    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'lettr:init
                            {--force : Skip confirmations and use defaults}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Set up Lettr for your Laravel application';

    public function __construct(
        protected readonly Filesystem $files,
    ) {
        parent::__construct();
    }

    /**
     * Async promise for fetching domains.
     */
    protected ?PromiseInterface $domainsPromise = null;

    /**
     * Async promise for fetching templates.
     */
    protected ?PromiseInterface $templatesPromise = null;

    /**
     * Sample template for personalized examples.
     *
     * @var array{slug: string, name: string}|null
     */
    protected ?array $sampleTemplate = null;

    /**
     * Whether user chose to use Blade templates (local rendering).
     */
    protected bool $usingBladeTemplates = false;

    /**
     * Whether user generated the template enum.
     */
    protected bool $generatedEnum = false;

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $this->displayLettrHeader('Init');

        // Start fetching domains and templates async immediately if API key exists
        $this->startAsyncFetches();

        // Step 1: API Key
        $apiKey = $this->setupApiKey();

        if ($apiKey === null) {
            $this->components->error('API key is required to continue.');

            return self::FAILURE;
        }

        // Step 2: Publish config
        $this->publishConfig();

        // Step 3: Setup mailer
        $this->setupMailer();

        // Step 4: Write API key to .env and update runtime config
        $this->writeEnvVariable('LETTR_API_KEY', $apiKey);
        config()->set('lettr.api_key', $apiKey);

        // Restart async fetches with the new/confirmed API key
        $this->startAsyncFetches();

        note('Configuration saved to .env and config/lettr.php');

        // Step 5: Template workflow
        $keptLocalTemplates = $this->handleTemplateWorkflow();

        // Step 6: Setup sending domain (silently fetch, skip if fails)
        $domainOptions = $this->fetchDomains();
        $hasSendingDomain = false;
        if ($domainOptions !== null) {
            $this->setupSendingDomain($domainOptions);
            $hasSendingDomain = true;
        }

        // Step 7: Fetch a sample template for personalized examples
        $this->fetchSampleTemplate();

        // Step 8: Set MAIL_MAILER (only for local templates flow)
        if ($keptLocalTemplates) {
            $setMailer = confirm(
                label: 'Set MAIL_MAILER=lettr in your .env?',
                default: true,
                hint: 'This will send all emails through Lettr',
            );

            if ($setMailer) {
                $this->writeEnvVariable('MAIL_MAILER', 'lettr');
            }
        }

        // Final success message
        $this->displayOutro($keptLocalTemplates, $hasSendingDomain);

        return self::SUCCESS;
    }

    /**
     * Display the final success message.
     */
    protected function displayOutro(bool $keptLocalTemplates, bool $hasSendingDomain): void
    {
        $this->newLine();
        $this->output->writeln($this->displayBadge(' ✓ Setup Complete '));
        $this->newLine();

        if ($keptLocalTemplates) {
            $this->line('  Your emails will be sent through Lettr.');
            $this->line('  Templates remain in your codebase for version control.');
        } else {
            // Use sample template for personalized examples, or fall back to generic
            $slug = $this->sampleTemplate['slug'] ?? 'welcome';
            $className = $this->sampleTemplate ? Str::studly($this->sampleTemplate['slug']) : 'Welcome';

            if ($this->usingBladeTemplates) {
                // Blade mode - only show Mailable example
                $this->line('  Send emails using:');
                $this->newLine();
                $this->line("    <fg=cyan>Mail::to(\$user)->send(new {$className}(\$data));</>");
            } else {
                // API mode - show both examples
                $this->line('  Send emails using:');
                $this->newLine();
                if ($this->generatedEnum) {
                    $this->line("    <fg=cyan>Mail::lettr()->sendTemplate(LettrTemplate::{$className}, \$data, \$to);</>");
                } else {
                    $this->line("    <fg=cyan>Mail::lettr()->sendTemplate('{$slug}', \$data, \$to);</>");
                }
                $this->newLine();
                $this->line('  Or with generated Mailables:');
                $this->newLine();
                $this->line("    <fg=cyan>Mail::to(\$user)->send(new {$className}(\$data));</>");
            }
        }

        $this->newLine();

        if (! $hasSendingDomain) {
            $this->line('  <fg=yellow>⚠</> <fg=yellow>No verified sending domain found.</>');
            $this->line('    Set up your domain: '.$this->hyperlink('https://app.lettr.com/domains/sending', 'https://app.lettr.com/domains/sending'));
            $this->newLine();
        }

        $this->line('  <fg=gray>Docs:</> '.$this->hyperlink('https://docs.lettr.com', 'https://docs.lettr.com'));
        $this->newLine();
    }

    /**
     * Ask for and validate the API key.
     */
    protected function setupApiKey(): ?string
    {
        $existingKey = config('lettr.api_key');

        if (is_string($existingKey) && $existingKey !== '') {
            if ($this->option('force') || confirm(
                label: 'An API key is already configured. Do you want to replace it?',
                default: false,
            )) {
                return $this->askForApiKey();
            }

            return $existingKey;
        }

        return $this->askForApiKey();
    }

    /**
     * Prompt the user for their API key.
     */
    protected function askForApiKey(): ?string
    {
        $apiKey = password(
            label: 'Enter your Lettr API key',
            placeholder: 'lttr_xxxx',
            required: 'An API key is required to use Lettr.',
            hint: 'Find your API key at https://lettr.app/settings/api',
        );

        if ($apiKey === '') {
            return null;
        }

        return $apiKey;
    }

    /**
     * Publish the Lettr config file.
     */
    protected function publishConfig(): void
    {
        $configPath = config_path('lettr.php');

        if ($this->files->exists($configPath)) {
            if (! $this->option('force') && ! confirm(
                label: 'config/lettr.php already exists. Overwrite it?',
                default: false,
            )) {
                return;
            }
        }

        $this->callSilently('vendor:publish', [
            '--tag' => 'lettr-config',
            '--force' => true,
        ]);
    }

    /**
     * Add the Lettr mailer to config/mail.php.
     */
    protected function setupMailer(): void
    {
        $mailConfigPath = config_path('mail.php');

        if (! $this->files->exists($mailConfigPath)) {
            $this->components->warn('config/mail.php not found. Skipping mailer setup.');
            $this->components->info('Add the lettr mailer manually to your mail config.');

            return;
        }

        $mailConfig = $this->files->get($mailConfigPath);

        // Check if lettr mailer is already configured
        if (str_contains($mailConfig, "'lettr'")) {
            return;
        }

        // Find the mailers array and add lettr
        $lettrMailer = <<<'PHP'

        'lettr' => [
            'transport' => 'lettr',
        ],
PHP;

        // Try to insert after the 'mailers' => [ line
        $pattern = "/('mailers'\s*=>\s*\[)/";

        if (preg_match($pattern, $mailConfig)) {
            $mailConfig = preg_replace(
                $pattern,
                "$1{$lettrMailer}",
                $mailConfig,
                1
            ) ?? $mailConfig;

            $this->files->put($mailConfigPath, $mailConfig);
        } else {
            $this->components->warn('Could not auto-configure the lettr mailer.');
            $this->showManualMailerInstructions();
        }
    }

    /**
     * Show instructions for manual mailer setup.
     */
    protected function showManualMailerInstructions(): void
    {
        $this->newLine();
        $this->components->info('Add this to your config/mail.php mailers array:');
        $this->line("    'lettr' => [");
        $this->line("        'transport' => 'lettr',");
        $this->line('    ],');
        $this->newLine();
    }

    /**
     * Start fetching domains and templates asynchronously in the background.
     */
    protected function startAsyncFetches(): void
    {
        $apiKey = config('lettr.api_key');

        if (! is_string($apiKey) || $apiKey === '') {
            return;
        }

        /** @var PromiseInterface $domainsPromise */
        $domainsPromise = Http::async()
            ->withToken($apiKey)
            ->get('https://app.lettr.com/api/domains');
        $this->domainsPromise = $domainsPromise;

        /** @var PromiseInterface $templatesPromise */
        $templatesPromise = Http::async()
            ->withToken($apiKey)
            ->get('https://app.lettr.com/api/templates', ['per_page' => 1]);
        $this->templatesPromise = $templatesPromise;
    }

    /**
     * Fetch domains silently, using async result if available.
     *
     * @return array<string, string>|null Domain options or null if fetch failed
     */
    protected function fetchDomains(): ?array
    {
        // Try to get result from async promise first
        if ($this->domainsPromise !== null) {
            try {
                /** @var \Illuminate\Http\Client\Response $response */
                $response = $this->domainsPromise->wait();

                if ($response->successful()) {
                    /** @var array<int, array{domain: string, can_send: bool}> $domainsData */
                    $domainsData = $response->json('data.domains', []);

                    $sendableDomains = [];
                    foreach ($domainsData as $domainData) {
                        if (! empty($domainData['can_send'])) {
                            $domain = $domainData['domain'];
                            $sendableDomains[$domain] = $domain;
                        }
                    }

                    return empty($sendableDomains) ? null : $sendableDomains;
                }
            } catch (\Throwable) {
                // Fall through to SDK approach
            }
        }

        // Fall back to SDK (synchronous)
        /** @var LettrManager $lettr */
        $lettr = app('lettr');

        try {
            $domains = $lettr->domains()->list();
        } catch (\Throwable) {
            return null;
        }

        // Filter domains that can send
        $sendableDomains = [];
        foreach ($domains->all() as $domain) {
            if ($domain->canSend) {
                $sendableDomains[(string) $domain->domain] = (string) $domain->domain;
            }
        }

        return empty($sendableDomains) ? null : $sendableDomains;
    }

    /**
     * Fetch a sample template for personalized examples.
     */
    protected function fetchSampleTemplate(): void
    {
        // Try to get result from async promise first
        if ($this->templatesPromise !== null) {
            try {
                /** @var \Illuminate\Http\Client\Response $response */
                $response = $this->templatesPromise->wait();

                if ($response->successful()) {
                    /** @var array<int, array{slug: string, name: string}> $templates */
                    $templates = $response->json('data.templates', []);

                    if (! empty($templates)) {
                        $this->sampleTemplate = [
                            'slug' => $templates[0]['slug'],
                            'name' => $templates[0]['name'],
                        ];

                        return;
                    }
                }
            } catch (\Throwable) {
                // Fall through to SDK approach
            }
        }

        // Fall back to SDK (synchronous)
        try {
            /** @var LettrManager $lettr */
            $lettr = app('lettr');
            $response = $lettr->templates()->list(new \Lettr\Dto\Template\ListTemplatesFilter(perPage: 1));
            $templates = $response->templates->all();

            if (! empty($templates)) {
                $this->sampleTemplate = [
                    'slug' => $templates[0]->slug,
                    'name' => $templates[0]->name,
                ];
            }
        } catch (\Throwable) {
            // Silently fail - will use default examples
        }
    }

    /**
     * Setup the sending domain if domains are available.
     *
     * @param  array<string, string>  $domainOptions
     */
    protected function setupSendingDomain(array $domainOptions): void
    {
        $this->newLine();

        // Get current MAIL_FROM_ADDRESS from config to pre-select
        $currentFromAddress = config('mail.from.address', '');
        $currentFromAddress = is_string($currentFromAddress) ? $currentFromAddress : '';
        $currentDomain = '';
        if ($currentFromAddress !== '' && str_contains($currentFromAddress, '@')) {
            $atPosition = strpos($currentFromAddress, '@');
            $currentDomain = $atPosition !== false ? substr($currentFromAddress, $atPosition + 1) : '';
        }

        // Default to current domain if it's in the list, otherwise first option
        $defaultDomain = isset($domainOptions[$currentDomain]) ? $currentDomain : array_key_first($domainOptions);

        $selectedDomain = select(
            label: 'Select a sending domain',
            options: $domainOptions,
            default: $defaultDomain,
            hint: 'Verified domains from your Lettr account',
        );

        // Ask for the local part of the email (before @)
        $currentLocalPart = 'hello';
        if ($currentFromAddress !== '' && str_contains($currentFromAddress, '@')) {
            $atPosition = strpos($currentFromAddress, '@');
            $currentLocalPart = $atPosition !== false ? substr($currentFromAddress, 0, $atPosition) : 'hello';
        }

        $localPart = text(
            label: 'Sender email address',
            default: $currentLocalPart,
            required: true,
            hint: "Will send from: {$currentLocalPart}@{$selectedDomain}",
        );

        $fullEmail = $localPart.'@'.$selectedDomain;

        // Write to .env
        $this->writeEnvVariable('MAIL_FROM_ADDRESS', $fullEmail);
        $this->newLine();
        note("Sender: {$fullEmail}");
    }

    /**
     * Handle the template workflow decision tree.
     *
     * @return bool Whether the user chose to keep templates locally (skipping Lettr sync)
     */
    protected function handleTemplateWorkflow(): bool
    {
        $hasLocalTemplates = confirm(
            label: 'Do you have existing email templates in your codebase?',
            default: false,
            hint: 'Blade files in resources/views/emails or similar',
        );

        if ($hasLocalTemplates) {
            return $this->handleExistingTemplates();
        }

        $this->handleNoLocalTemplates();

        return false;
    }

    /**
     * Handle the flow when user has existing local templates.
     *
     * @return bool Whether the user chose to keep templates locally (skipping Lettr sync)
     */
    protected function handleExistingTemplates(): bool
    {
        $keepLocal = confirm(
            label: 'Do you want to keep your templates in the codebase?',
            default: true,
            hint: 'Choose "No" to push them to Lettr and manage them there',
        );

        if ($keepLocal) {
            // User wants to keep templates local - no sync with Lettr
            return true;
        }

        // Push templates to Lettr
        $this->newLine();
        $this->call('lettr:push');
        $this->newLine();

        // Offer to generate type-safe classes
        // Templates are already local, no need to download, but use Blade mode
        $this->offerTypeGeneration(downloadTemplates: false, useBladeMode: true);

        return false;
    }

    /**
     * Handle the flow when user has no local templates.
     */
    protected function handleNoLocalTemplates(): void
    {
        $downloadTemplates = confirm(
            label: 'Do you want to download templates from your Lettr account?',
            default: false,
            hint: 'This will pull templates as Blade files for local rendering',
        );

        $this->offerTypeGeneration(downloadTemplates: $downloadTemplates);
    }

    /**
     * Offer to generate type-safe classes (enum, DTOs, mailables).
     *
     * @param  bool  $downloadTemplates  Whether to download templates from Lettr
     * @param  bool|null  $useBladeMode  Whether to generate Blade-based mailables (null = same as $downloadTemplates)
     */
    protected function offerTypeGeneration(bool $downloadTemplates = true, ?bool $useBladeMode = null): void
    {
        // Default: use Blade mode if downloading templates
        $useBladeMode ??= $downloadTemplates;

        $options = [
            'enum' => 'Template enum (LettrTemplate::WelcomeEmail)',
            'dtos' => 'DTOs for merge tags (WelcomeEmailData)',
            'mailables' => 'Mailable classes (WelcomeEmail extends LettrMailable)',
        ];

        $defaults = ['enum', 'dtos', 'mailables'];

        $generate = multiselect(
            label: 'Which type-safe classes do you want to generate?',
            options: $options,
            default: $defaults,
            hint: 'Space to toggle, Enter to confirm',
        );

        if (empty($generate)) {
            $this->newLine();
            note('Skipped. Generate classes later with:');
            $this->line('  php artisan lettr:generate-enum');
            $this->line('  php artisan lettr:generate-dtos');
            $this->line('  php artisan lettr:pull --with-mailables');

            return;
        }

        $this->newLine();

        $wantsMailables = in_array('mailables', $generate, true);
        $wantsEnum = in_array('enum', $generate, true);
        $wantsDtos = in_array('dtos', $generate, true);

        // Generate enum first if selected
        if ($wantsEnum) {
            $this->generatedEnum = true;
            $this->call('lettr:generate-enum');
            $this->newLine();
        }

        if ($wantsMailables) {
            // Track whether we're using Blade mode for the outro message
            $this->usingBladeTemplates = $useBladeMode;

            // Mailables require pull command (generates DTOs + mailables)
            $pullOptions = ['--with-mailables' => true];

            if (! $downloadTemplates) {
                // Skip template downloads
                $pullOptions['--skip-templates'] = true;
            }

            if (! $useBladeMode) {
                // Generate API-based mailables (with templateSlug)
                $pullOptions['--as-html'] = true;
            }

            $this->call('lettr:pull', $pullOptions);
        } elseif ($wantsDtos) {
            // Only DTOs requested (no mailables)
            $this->call('lettr:generate-dtos');
        }

        $this->newLine();
    }

    /**
     * Write a variable to the .env file.
     */
    protected function writeEnvVariable(string $key, string $value): void
    {
        $envPath = base_path('.env');

        if (! $this->files->exists($envPath)) {
            // Create .env from .env.example if it exists
            $examplePath = base_path('.env.example');

            if ($this->files->exists($examplePath)) {
                $this->files->copy($examplePath, $envPath);
            } else {
                $this->files->put($envPath, '');
            }
        }

        $envContent = $this->files->get($envPath);

        // Check if the key already exists
        $pattern = "/^{$key}=.*/m";

        if (preg_match($pattern, $envContent)) {
            // Replace existing value
            $envContent = preg_replace($pattern, "{$key}={$value}", $envContent) ?? $envContent;
        } else {
            // Append new variable
            $envContent = rtrim($envContent)."\n\n{$key}={$value}\n";
        }

        $this->files->put($envPath, $envContent);
    }
}
