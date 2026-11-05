<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\View;
use Lettr\Contracts\TransporterContract;
use Lettr\Laravel\Mail\LettrMailable;
use Lettr\Lettr;

class BladeWelcomeSend extends LettrMailable
{
    protected ?string $bladeView = 'lettr-send-test::welcome';

    public function withMergeTags(): array
    {
        return ['name' => 'John'];
    }
}

/**
 * Send through the real `lettr` mailer and transport, faking only HTTP, and
 * return the payload that would have reached the Lettr API.
 *
 * @return array{uri: string, data: array<string, mixed>}
 */
function lettrPayloadFor(LettrMailable $mailable): array
{
    $transporter = new class implements TransporterContract
    {
        /** @var array{uri: string, data: array<string, mixed>}|null */
        public ?array $last = null;

        public function post(string $uri, array $data): array
        {
            $this->last = ['uri' => $uri, 'data' => $data];

            if (str_contains($uri, 'scheduled')) {
                return [
                    'request_id' => 'sch_1', 'transmission_id' => null, 'state' => 'scheduled',
                    'scheduled_at' => '2030-01-01T10:00:00Z', 'from' => 'from@example.com', 'from_name' => null,
                    'subject' => 'Ahojky', 'recipients' => ['john@example.com'], 'num_recipients' => 1,
                    'accepted' => 1, 'rejected' => 0, 'tag' => null, 'failure_reason' => null, 'events' => [],
                ];
            }

            return ['request_id' => 'req-1', 'accepted' => 1, 'rejected' => 0];
        }

        public function postExpectingEnvelope(string $uri, ?array $data = null): array
        {
            $this->last = ['uri' => $uri, 'data' => $data ?? []];

            return ['data' => ['request_id' => 'req-1', 'accepted' => 1, 'rejected' => 0]];
        }

        public function get(string $uri): array
        {
            return [];
        }

        public function getWithQuery(string $uri, array $query = []): array
        {
            return [];
        }

        public function put(string $uri, array $data): array
        {
            return [];
        }

        public function patch(string $uri, array $data): array
        {
            return [];
        }

        public function delete(string $uri): void {}

        public function deleteWithBody(string $uri, array $data): array
        {
            return [];
        }

        public function lastResponseHeaders(): array
        {
            return [];
        }

        public function lastStatusCode(): ?int
        {
            return 200;
        }
    };

    app()->instance(Lettr::class, new Lettr($transporter));
    app()->forgetInstance('lettr');
    Mail::purge('lettr');

    Mail::mailer('lettr')->to('john@example.com')->send($mailable);

    expect($transporter->last)->not->toBeNull();

    return $transporter->last;
}

beforeEach(function (): void {
    $dir = sys_get_temp_dir().'/lettr-blade-send-test';
    @mkdir($dir);
    file_put_contents($dir.'/welcome.blade.php', '<p>Hello {{ $name }}</p>');
    View::addNamespace('lettr-send-test', $dir);

    config()->set('mail.mailers.lettr', ['transport' => 'lettr']);
    config()->set('mail.from', ['address' => 'from@example.com', 'name' => 'Sender']);
});

it('sends a Blade mailable to the Lettr API tagged with its class name', function (): void {
    $payload = lettrPayloadFor((new BladeWelcomeSend)->subject('Ahojky'));

    expect($payload['data']['tag'] ?? null)->toBe('blade-welcome-send')
        ->and($payload['data'])->not->toHaveKey('template_slug')
        ->and($payload['data'])->not->toHaveKey('substitution_data')
        ->and((string) ($payload['data']['html'] ?? ''))->toContain('Hello John');
});

it('sends an explicit tag instead of the class name', function (): void {
    $payload = lettrPayloadFor((new BladeWelcomeSend)->subject('Ahojky')->tag('welcome'));

    expect($payload['data']['tag'] ?? null)->toBe('welcome');
});

it('routes a scheduled Blade mailable to the scheduled endpoint', function (): void {
    $payload = lettrPayloadFor((new BladeWelcomeSend)->subject('Ahojky')->scheduledAt('2030-01-01T10:00:00+00:00'));

    expect($payload['uri'])->toContain('scheduled')
        ->and($payload['data']['tag'] ?? null)->toBe('blade-welcome-send');
});
