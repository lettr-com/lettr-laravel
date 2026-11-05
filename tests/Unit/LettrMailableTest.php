<?php

use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\SentMessage;
use Illuminate\Support\Facades\Mail;
use Lettr\Laravel\Mail\LettrMailable;
use Symfony\Component\Mime\Email;

/**
 * These send for real, through the array mailer, rather than through Mail::fake():
 * a fake never renders the message, so it cannot tell whether the Lettr setup ran.
 */
function sendThroughArrayMailer(LettrMailable $mailable): Email
{
    $sent = Mail::mailer('array')->to('user@example.com')->send($mailable);

    expect($sent)->toBeInstanceOf(SentMessage::class);

    $message = $sent->getOriginalMessage();
    assert($message instanceof Email);

    return $message;
}

/**
 * @return list<string>
 */
function headerValues(Email $message, string $name): array
{
    return array_map(
        fn ($header) => $header->getBodyAsString(),
        iterator_to_array($message->getHeaders()->all($name), false),
    );
}

it('sends when build() is overridden without calling parent::build()', function () {
    $message = sendThroughArrayMailer(new class extends LettrMailable
    {
        public function build(): static
        {
            return $this
                ->template('welcome-email', version: 2)
                ->substitutionData(['user_name' => 'John']);
        }
    });

    expect(headerValues($message, 'X-Lettr-Template-Slug'))->toBe(['welcome-email'])
        ->and(headerValues($message, 'X-Lettr-Template-Version'))->toBe(['2'])
        ->and(json_decode(base64_decode(headerValues($message, 'X-Lettr-Substitution-Data')[0]), true))
        ->toBe(['user_name' => 'John'])
        ->and($message->getHtmlBody())->toContain('welcome-email');
});

it('sends when parent::build() is called before the template is set', function () {
    $message = sendThroughArrayMailer(new class extends LettrMailable
    {
        public function build(): static
        {
            return parent::build()->template('welcome-email')->idempotencyKey('order-1-shipped');
        }
    });

    expect(headerValues($message, 'X-Lettr-Template-Slug'))->toBe(['welcome-email'])
        ->and(headerValues($message, 'X-Lettr-Idempotency-Key'))->toBe(['order-1-shipped']);
});

it('sends when parent::build() is called after the template is set, without duplicate headers', function () {
    $message = sendThroughArrayMailer(new class extends LettrMailable
    {
        public function build(): static
        {
            $this->template('welcome-email')->customHeaders(['X-Campaign-Id' => 'welcome-2024']);

            return parent::build();
        }
    });

    expect(headerValues($message, 'X-Lettr-Template-Slug'))->toBe(['welcome-email'])
        ->and(headerValues($message, 'X-Campaign-Id'))->toBe(['welcome-2024']);
});

it('sends when the template is set in the constructor and build() is not overridden', function () {
    $message = sendThroughArrayMailer(new class extends LettrMailable
    {
        public function __construct()
        {
            $this->template('welcome-email');
        }
    });

    expect(headerValues($message, 'X-Lettr-Template-Slug'))->toBe(['welcome-email']);
});

it('merges withMergeTags() into the substitution data, with explicit data winning', function () {
    $message = sendThroughArrayMailer(new class extends LettrMailable
    {
        public function build(): static
        {
            return $this->template('welcome-email')->substitutionData(['name' => 'Explicit']);
        }

        public function withMergeTags(): array
        {
            return ['name' => 'From tags', 'plan' => 'pro'];
        }
    });

    expect(json_decode(base64_decode(headerValues($message, 'X-Lettr-Substitution-Data')[0]), true))
        ->toBe(['name' => 'Explicit', 'plan' => 'pro']);
});

it('routes the mailable through the lettr mailer by default', function () {
    $mailable = new class extends LettrMailable
    {
        public function build(): static
        {
            return $this->template('welcome-email');
        }
    };

    sendThroughArrayMailer($mailable);

    // Laravel names the sending mailer ('array') on the mailable before preparing it.
    // The property is what a queued send resolves its transport from, so a template
    // mailable must still end up on 'lettr' or the template headers go unread.
    expect($mailable->mailer)->toBe('lettr');
});

it('keeps a mailer the subclass chose in build()', function () {
    $mailable = new class extends LettrMailable
    {
        public function build(): static
        {
            return parent::build()->template('welcome-email')->mailer('log');
        }
    };

    sendThroughArrayMailer($mailable);

    expect($mailable->mailer)->toBe('log');
});

it('adds an auto-generated tag when no template is used', function () {
    $message = sendThroughArrayMailer(new class extends LettrMailable
    {
        public function build(): static
        {
            return $this->html('<p>Hi</p>');
        }
    });

    expect(headerValues($message, 'X-Lettr-Tag'))->toHaveCount(1)
        ->and(headerValues($message, 'X-Lettr-Template-Slug'))->toBe([]);
});

it('leaves Blade view mode alone', function () {
    $this->app['view']->addNamespace('lettr-test', __DIR__.'/../fixtures/views');

    $mailable = new class extends LettrMailable
    {
        protected ?string $bladeView = 'lettr-test::plain';

        public function build(): static
        {
            return $this;
        }
    };

    $message = sendThroughArrayMailer($mailable);

    expect($message->getHtmlBody())->toContain('Plain Blade body')
        ->and(headerValues($message, 'X-Lettr-Template-Slug'))->toBe([])
        ->and(headerValues($message, 'X-Lettr-Tag'))->toBe([])
        ->and($mailable->mailer)->toBe('array');
});

it('renders a template mailable whose build() does not call the parent', function () {
    $html = (new class extends LettrMailable
    {
        public function build(): static
        {
            return $this->template('welcome-email');
        }
    })->render();

    expect($html)->toContain('welcome-email');
});

it('keeps HTML the subclass set after parent::build()', function () {
    $message = sendThroughArrayMailer(new class extends LettrMailable
    {
        public function build(): static
        {
            return parent::build()->template('welcome-email')->html('<p>Custom preview</p>');
        }
    });

    expect($message->getHtmlBody())->toBe('<p>Custom preview</p>')
        ->and(headerValues($message, 'X-Lettr-Template-Slug'))->toBe(['welcome-email']);
});

it('updates the placeholder when the template changes between sends', function () {
    $mailable = new class extends LettrMailable
    {
        public function build(): static
        {
            return $this->templateSlug === null ? $this->template('first') : $this;
        }
    };

    sendThroughArrayMailer($mailable);
    $mailable->template('second');
    $message = sendThroughArrayMailer($mailable);

    expect($message->getHtmlBody())->toContain('second')
        ->and(headerValues($message, 'X-Lettr-Template-Slug'))->toBe(['second']);
});

it('keeps a mailer build() chose even when it matches the one already set', function () {
    $mailable = new class extends LettrMailable
    {
        public function build(): static
        {
            // 'array' is also the mailer the send below names on the mailable first
            return $this->template('welcome-email')->mailer('array');
        }
    };

    sendThroughArrayMailer($mailable);

    expect($mailable->mailer)->toBe('array');
});

it('sends a clone with its own headers', function () {
    $original = new class extends LettrMailable
    {
        public function build(): static
        {
            return $this->templateSlug === null ? $this->template('original') : $this;
        }
    };

    sendThroughArrayMailer($original);

    $copy = clone $original;
    $copy->template('copy')->idempotencyKey('copy-key');
    $message = sendThroughArrayMailer($copy);

    expect(headerValues($message, 'X-Lettr-Template-Slug'))->toBe(['copy'])
        ->and(headerValues($message, 'X-Lettr-Idempotency-Key'))->toBe(['copy-key']);
});

it('adds each header once when the same instance is sent twice', function () {
    $mailable = new class extends LettrMailable
    {
        public function build(): static
        {
            return parent::build()->template('welcome-email');
        }
    };

    sendThroughArrayMailer($mailable);
    $message = sendThroughArrayMailer($mailable);

    expect(headerValues($message, 'X-Lettr-Template-Slug'))->toBe(['welcome-email']);
});

it('reads fresh merge tags on every send', function () {
    $mailable = new class extends LettrMailable
    {
        public string $status = 'pending';

        public function build(): static
        {
            return $this->template('order-status');
        }

        public function withMergeTags(): array
        {
            return ['status' => $this->status];
        }
    };

    sendThroughArrayMailer($mailable);
    $mailable->status = 'shipped';
    $message = sendThroughArrayMailer($mailable);

    expect(json_decode(base64_decode(headerValues($message, 'X-Lettr-Substitution-Data')[0]), true))
        ->toBe(['status' => 'shipped']);
});

it('routes a queued send through the lettr mailer even when another mailer is the default', function () {
    // Stand in for the Lettr transport so the queued job can be observed end to end
    config([
        'mail.default' => 'log',
        'mail.mailers.lettr' => ['transport' => 'array'],
        'queue.default' => 'sync',
    ]);

    Mail::to('user@example.com')->queue(new QueuedWelcomeMailable);

    $messages = Mail::mailer('lettr')->getSymfonyTransport()->messages();

    expect($messages)->toHaveCount(1)
        ->and(headerValues($messages[0]->getOriginalMessage(), 'X-Lettr-Template-Slug'))->toBe(['welcome-email']);
});

/**
 * Named, because a queued job is serialized and anonymous classes cannot be.
 */
class QueuedWelcomeMailable extends LettrMailable implements ShouldQueue
{
    public function build(): static
    {
        return $this->template('welcome-email');
    }
}
