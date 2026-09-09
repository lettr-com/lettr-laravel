<?php

declare(strict_types=1);

namespace Lettr\Laravel\Mail;

use DateTimeInterface;
use Illuminate\Contracts\Mail\Mailable as MailableContract;
use Illuminate\Contracts\Mail\Mailer as MailerContract;
use Illuminate\Contracts\Support\Arrayable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\PendingMail;
use Illuminate\Mail\SentMessage;

/**
 * Extended PendingMail that supports Lettr template sending.
 */
class LettrPendingMail extends PendingMail
{
    /** @var array{address: string, name: ?string}|null */
    protected ?array $fromAddress = null;

    protected ?string $scheduledAt = null;

    protected ?string $idempotencyKey = null;

    protected bool $withoutIdempotency = false;

    public function __construct(MailerContract $mailer)
    {
        parent::__construct($mailer);
    }

    /**
     * Set the sender for the email.
     */
    public function from(string $address, ?string $name = null): static
    {
        $this->fromAddress = compact('address', 'name');

        return $this;
    }

    /**
     * Schedule delivery of the email to the given time via the Lettr API.
     */
    public function scheduleAt(DateTimeInterface|string $when): static
    {
        $this->scheduledAt = $when instanceof DateTimeInterface
            ? $when->format(DateTimeInterface::ATOM)
            : $when;

        return $this;
    }

    /**
     * Send this email under a specific idempotency key.
     *
     * Only applies to Lettr mailables - the key travels as a header the Lettr
     * transport reads, and a plain Laravel mailable has nowhere to put it that
     * survives being queued. Add the header yourself from the mailable's own
     * `headers()` method for those.
     */
    public function idempotencyKey(string $key): static
    {
        $this->idempotencyKey = $key;
        $this->withoutIdempotency = false;

        return $this;
    }

    /**
     * Send with no idempotency key, for a deliberate resend.
     */
    public function withoutIdempotency(): static
    {
        $this->withoutIdempotency = true;
        $this->idempotencyKey = null;

        return $this;
    }

    /**
     * Send a new mailable message instance, applying from address and scheduledAt if set.
     */
    public function send(MailableContract $mailable): ?SentMessage
    {
        if ($this->fromAddress !== null && $mailable instanceof Mailable) {
            $mailable->from($this->fromAddress['address'], $this->fromAddress['name']);
        }

        if ($this->scheduledAt !== null && $mailable instanceof LettrMailable) {
            $mailable->scheduledAt($this->scheduledAt);
        }

        if ($mailable instanceof LettrMailable) {
            if ($this->withoutIdempotency) {
                $mailable->withoutIdempotency();
            } elseif ($this->idempotencyKey !== null) {
                $mailable->idempotencyKey($this->idempotencyKey);
            }
        }

        return parent::send($mailable);
    }

    /**
     * Send a Lettr template.
     *
     * @param  array<string, mixed>|Arrayable<string, mixed>  $substitutionData
     * @param  string|null  $tag  Override the default tag (template slug). If null, server uses template slug.
     * @param  string|null  $subject  Override the template's default subject line.
     * @param  array<string, string>  $customHeaders  Custom headers to send with the email.
     */
    public function sendTemplate(
        string $templateSlug,
        ?string $subject = null,
        array|Arrayable $substitutionData = [],
        ?int $version = null,
        ?string $tag = null,
        array $customHeaders = [],
    ): ?SentMessage {
        if ($substitutionData instanceof Arrayable) {
            $substitutionData = $substitutionData->toArray();
        }

        $mailable = new InlineLettrMailable(
            $templateSlug,
            $subject,
            $substitutionData,
            $version,
            $tag,
            $customHeaders,
            $this->scheduledAt,
        );

        return $this->send($mailable);
    }
}
