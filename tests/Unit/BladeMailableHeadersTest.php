<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\View;
use Lettr\Laravel\Mail\LettrMailable;
use Symfony\Component\Mime\Email;

class BladeOrderConfirmation extends LettrMailable
{
    protected ?string $bladeView = 'lettr-test::order';

    public function withMergeTags(): array
    {
        return ['name' => 'John'];
    }
}

class ThisBladeMailableHasAVeryLongClassNameGeneratedFromAnEquallyLongTemplateSlug extends BladeOrderConfirmation {}

class TemplateOrderConfirmation extends LettrMailable
{
    protected ?string $templateSlug = 'order-confirmation';
}

beforeEach(function (): void {
    $dir = sys_get_temp_dir().'/lettr-blade-mailable-test';
    @mkdir($dir);
    file_put_contents($dir.'/order.blade.php', '<p>Hello {{ $name }}</p>');
    View::addNamespace('lettr-test', $dir);

    config()->set('mail.default', 'array');
});

function sentEmail(LettrMailable $mailable): Email
{
    $sent = Mail::to('john@example.com')->send($mailable);

    $message = $sent?->getOriginalMessage();
    assert($message instanceof Email);

    return $message;
}

it('tags a Blade mailable with its kebab-case class name', function (): void {
    $email = sentEmail(new BladeOrderConfirmation);

    expect($email->getHeaders()->get('X-Lettr-Tag')?->getBodyAsString())
        ->toBe('blade-order-confirmation');
});

it('lets an explicit tag win over the class-name tag on a Blade mailable', function (): void {
    $email = sentEmail((new BladeOrderConfirmation)->tag('orders'));

    expect(iterator_to_array($email->getHeaders()->all('X-Lettr-Tag'), false))->toHaveCount(1)
        ->and($email->getHeaders()->get('X-Lettr-Tag')?->getBodyAsString())->toBe('orders');
});

it('sends custom headers and scheduled-at on a Blade mailable', function (): void {
    $email = sentEmail(
        (new BladeOrderConfirmation)
            ->customHeaders(['X-Campaign' => 'autumn'])
            ->scheduledAt('2026-12-01T10:00:00+00:00')
    );

    expect($email->getHeaders()->get('X-Campaign')?->getBodyAsString())->toBe('autumn')
        ->and($email->getHeaders()->get('X-Lettr-Scheduled-At')?->getBodyAsString())
        ->toBe('2026-12-01T10:00:00+00:00');
});

it('sends idempotency headers on a Blade mailable', function (): void {
    $keyed = sentEmail((new BladeOrderConfirmation)->idempotencyKey('order-42'));
    $disabled = sentEmail((new BladeOrderConfirmation)->withoutIdempotency());

    expect($keyed->getHeaders()->get('X-Lettr-Idempotency-Key')?->getBodyAsString())->toBe('order-42')
        ->and($disabled->getHeaders()->get('X-Lettr-Idempotency')?->getBodyAsString())->toBe('disabled');
});

it('keeps template headers and substitution data off a Blade mailable', function (): void {
    $email = sentEmail(new BladeOrderConfirmation);

    expect($email->getHeaders()->has('X-Lettr-Template-Slug'))->toBeFalse()
        ->and($email->getHeaders()->has('X-Lettr-Substitution-Data'))->toBeFalse()
        ->and($email->getHtmlBody())->toContain('Hello John');
});

it('still leaves the tag to the server for a Lettr template mailable', function (): void {
    $email = sentEmail(new TemplateOrderConfirmation);

    expect($email->getHeaders()->has('X-Lettr-Tag'))->toBeFalse()
        ->and($email->getHeaders()->get('X-Lettr-Template-Slug')?->getBodyAsString())->toBe('order-confirmation');
});

it('caps the generated tag at the 64 characters the API accepts', function (): void {
    $email = sentEmail(new ThisBladeMailableHasAVeryLongClassNameGeneratedFromAnEquallyLongTemplateSlug);

    $tag = $email->getHeaders()->get('X-Lettr-Tag')?->getBodyAsString();

    expect(strlen((string) $tag))->toBeLessThanOrEqual(64)
        ->and($tag)->toStartWith('this-blade-mailable-has-a-very-long-class-name')
        ->and($tag)->not->toEndWith('-');
});

it('sends each header once when a mailable is built more than once', function (string $class): void {
    $mailable = (new $class)->customHeaders(['X-Campaign' => 'autumn'])->idempotencyKey('order-42');
    $mailable->render();

    $headers = sentEmail($mailable)->getHeaders();

    foreach (['X-Campaign', 'X-Lettr-Idempotency-Key'] as $name) {
        expect(iterator_to_array($headers->all($name), false))->toHaveCount(1);
    }
})->with([
    'blade' => [BladeOrderConfirmation::class],
    'template' => [TemplateOrderConfirmation::class],
]);

it('can still be queued after it has been rendered', function (string $class): void {
    $mailable = new $class;
    $mailable->render();

    expect(fn () => serialize($mailable))->not->toThrow(Exception::class);
})->with([
    'blade' => [BladeOrderConfirmation::class],
    'template' => [TemplateOrderConfirmation::class],
]);

it('keeps a Lettr header the app set itself instead of adding a second one', function (): void {
    $mailable = (new BladeOrderConfirmation)->withSymfonyMessage(function (Email $email): void {
        $email->getHeaders()->addTextHeader('X-Lettr-Tag', 'hand-set');
    });

    $headers = sentEmail($mailable)->getHeaders();

    expect(iterator_to_array($headers->all('X-Lettr-Tag'), false))->toHaveCount(1)
        ->and($headers->get('X-Lettr-Tag')?->getBodyAsString())->toBe('hand-set');
});

it('sends template headers and substitution data for a Lettr template mailable', function (): void {
    $headers = sentEmail(
        (new TemplateOrderConfirmation)->template('order-confirmation', 3, 7)->substitutionData(['name' => 'John'])
    )->getHeaders();

    expect($headers->get('X-Lettr-Template-Version')?->getBodyAsString())->toBe('3')
        ->and($headers->get('X-Lettr-Project-Id')?->getBodyAsString())->toBe('7')
        ->and(json_decode(base64_decode((string) $headers->get('X-Lettr-Substitution-Data')?->getBodyAsString()), true))
        ->toBe(['name' => 'John']);
});

it('keeps a hand-set X-Lettr-Tag on a plain HTML mailable too', function (): void {
    $mailable = (new class extends LettrMailable
    {
        public function build(): static
        {
            return $this->html('<p>Hi</p>');
        }
    })->withSymfonyMessage(function (Email $email): void {
        $email->getHeaders()->addTextHeader('X-Lettr-Tag', 'hand-set');
    });

    $headers = sentEmail($mailable)->getHeaders();

    expect(iterator_to_array($headers->all('X-Lettr-Tag'), false))->toHaveCount(1)
        ->and($headers->get('X-Lettr-Tag')?->getBodyAsString())->toBe('hand-set');
});
