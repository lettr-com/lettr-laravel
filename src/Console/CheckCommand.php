<?php

declare(strict_types=1);

namespace Lettr\Laravel\Console;

use Illuminate\Console\Command;
use Lettr\Collections\DomainCollection;
use Lettr\Dto\Domain\Domain;
use Lettr\Exceptions\LettrException;
use Lettr\Laravel\LettrManager;

class CheckCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'lettr:check';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Verify that your Lettr integration is correctly configured';

    private bool $allPassed = true;

    public function __construct(
        protected readonly LettrManager $lettr,
    ) {
        parent::__construct();
    }

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $this->newLine();

        $apiKeyValid = false;

        $this->checkMailer();
        $apiKeyValid = $this->checkApiKey();
        $this->checkSendingDomain($apiKeyValid);

        $this->newLine();

        return $this->allPassed ? self::SUCCESS : self::FAILURE;
    }

    private function checkMailer(): void
    {
        $mailerConfig = config('mail.mailers.lettr');
        $defaultMailer = config('mail.default');

        if ($mailerConfig === null) {
            $this->printResult(false, 'Mailer', 'not registered');
            $this->printHint(
                'Add the lettr mailer to config/mail.php:',
                "  'mailers' => ['lettr' => ['transport' => 'lettr']]",
            );

            return;
        }

        if ($defaultMailer !== 'lettr') {
            $this->printResult(false, 'Mailer', "lettr registered, but default is \"{$defaultMailer}\"");
            $this->printHint('Set MAIL_MAILER=lettr in your .env file');

            return;
        }

        $this->printResult(true, 'Mailer', 'lettr (default)');
    }

    private function checkApiKey(): bool
    {
        $apiKey = config('lettr.api_key');

        if (! is_string($apiKey) || $apiKey === '') {
            $this->printResult(false, 'API Key', 'not set');
            $this->printHint(
                'Set LETTR_API_KEY in your .env file',
                'Get your API key at https://app.lettr.com/api-keys',
            );

            return false;
        }

        try {
            $authStatus = $this->lettr->health()->authCheck();
            $this->printResult(true, 'API Key', "valid (team #{$authStatus->teamId})");

            return true;
        } catch (LettrException) {
            $this->printResult(false, 'API Key', 'invalid or expired');
            $this->printHint('Check your API key at https://app.lettr.com/api-keys');

            return false;
        }
    }

    private function checkSendingDomain(bool $apiKeyValid): void
    {
        $fromAddress = config('mail.from.address');

        if (! is_string($fromAddress) || $fromAddress === '' || ! str_contains($fromAddress, '@')) {
            $this->printResult(false, 'Sending Domain', 'mail.from.address is not configured');
            $this->printHint('Set MAIL_FROM_ADDRESS in your .env file');

            return;
        }

        $domain = strtolower(substr($fromAddress, (int) strpos($fromAddress, '@') + 1));

        if (! $apiKeyValid) {
            $this->printResult(false, 'Sending Domain', "{$fromAddress} — fix API key first to verify domain");

            return;
        }

        try {
            $domains = $this->lettr->domains()->list();
            $match = $this->findDomain($domains, $domain);

            if ($match === null) {
                $this->printResult(false, 'Sending Domain', "{$fromAddress} — domain not found in your account");
                $this->printHint('Add your domain at https://app.lettr.com/domains/sending');

                return;
            }

            if (! $match->canSend) {
                $this->printResult(false, 'Sending Domain', "{$fromAddress} — domain not verified");
                $this->printHint('Complete DNS verification at https://app.lettr.com/domains/sending');

                return;
            }

            $this->printResult(true, 'Sending Domain', $fromAddress);
        } catch (LettrException) {
            $this->printResult(false, 'Sending Domain', "{$fromAddress} — unable to reach Lettr API");
            $this->printHint('Check your network connection or try again later');
        }
    }

    private function findDomain(DomainCollection $domains, string $domain): ?Domain
    {
        foreach ($domains->all() as $d) {
            if ((string) $d->domain === $domain) {
                return $d;
            }
        }

        return null;
    }

    private function printResult(bool $pass, string $label, string $detail): void
    {
        if (! $pass) {
            $this->allPassed = false;
        }

        $icon = $pass ? '<fg=green>✓</>' : '<fg=red>✗</>';
        $dots = str_repeat('.', max(1, 20 - mb_strlen($label)));

        $this->line("  {$icon} {$label} {$dots} {$detail}");
    }

    private function printHint(string ...$lines): void
    {
        foreach ($lines as $line) {
            $this->line("    <fg=gray>{$line}</>");
        }
    }
}
