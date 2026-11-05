<?php

declare(strict_types=1);

namespace Lettr\Laravel\Mail;

use DateTimeInterface;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Mail\Message;
use Illuminate\Queue\SerializesModels;
use Symfony\Component\Mime\Email;

abstract class LettrMailable extends Mailable
{
    use Queueable;
    use SerializesModels;

    /**
     * The longest tag the Lettr API accepts.
     */
    private const MAX_GENERATED_TAG_LENGTH = 64;

    /**
     * The Lettr template slug (for API template mode).
     */
    protected ?string $templateSlug = null;

    /**
     * The Blade view for this email (for Blade view mode).
     *
     * @var view-string|null
     */
    protected ?string $bladeView = null;

    /**
     * The Lettr template version.
     */
    protected ?int $templateVersion = null;

    /**
     * The Lettr project ID.
     */
    protected ?int $projectId = null;

    /**
     * The substitution data for the template.
     *
     * @var array<string, mixed>
     */
    protected array $substitutionData = [];

    /**
     * Custom headers to send with the email.
     *
     * @var array<string, string>
     */
    protected array $customHeaders = [];

    /**
     * ISO 8601 timestamp at which the email should be sent.
     */
    protected ?string $scheduledAt = null;

    /**
     * A caller-supplied idempotency key, used instead of the generated one.
     */
    protected ?string $idempotencyKey = null;

    /**
     * Whether this send should carry no idempotency key at all.
     */
    protected bool $withoutIdempotency = false;

    /**
     * Whether prepareMailableForDelivery() is running the subclass's build().
     */
    private bool $lettrPreparing = false;

    /**
     * Whether build() picked a mailer, so the Lettr setup must not replace it.
     */
    private bool $lettrMailerChosen = false;

    /**
     * The placeholder HTML this class last set, to tell it from the subclass's own.
     */
    private ?string $lettrPlaceholderHtml = null;

    /**
     * Set the template slug.
     */
    public function template(string $slug, ?int $version = null, ?int $projectId = null): static
    {
        $this->templateSlug = $slug;
        $this->templateVersion = $version;
        $this->projectId = $projectId;

        return $this;
    }

    /**
     * Set the template version.
     */
    public function templateVersion(int $version): static
    {
        $this->templateVersion = $version;

        return $this;
    }

    /**
     * Set the project ID.
     */
    public function projectId(int $projectId): static
    {
        $this->projectId = $projectId;

        return $this;
    }

    /**
     * Set substitution data for the template.
     *
     * @param  array<string, mixed>  $data
     */
    public function substitutionData(array $data): static
    {
        $this->substitutionData = array_merge($this->substitutionData, $data);

        return $this;
    }

    /**
     * Set custom headers for the email.
     *
     * @param  array<string, string>  $headers
     */
    public function customHeaders(array $headers): static
    {
        $this->customHeaders = array_merge($this->customHeaders, $headers);

        return $this;
    }

    /**
     * Schedule the email for future delivery via the Lettr API.
     */
    public function scheduledAt(DateTimeInterface|string $when): static
    {
        $this->scheduledAt = $when instanceof DateTimeInterface
            ? $when->format(DateTimeInterface::ATOM)
            : $when;

        return $this;
    }

    /**
     * Send this email under a specific idempotency key.
     *
     * Use when you have a natural id for the send - an order or invoice number -
     * that is more meaningful than the generated one. The key must be stable
     * across retries of the same logical send, and must differ between sends
     * you want to happen separately.
     *
     * 1-255 characters of letters, digits, periods, underscores or hyphens.
     */
    public function idempotencyKey(string $key): static
    {
        $this->idempotencyKey = $key;
        $this->withoutIdempotency = false;

        return $this;
    }

    /**
     * Send this email with no idempotency key.
     *
     * For a deliberate resend: the same email, on purpose, again. Without this
     * a queued resend of an identical payload from the same job would come back
     * as a replay and never leave.
     */
    public function withoutIdempotency(): static
    {
        $this->withoutIdempotency = true;
        $this->idempotencyKey = null;

        return $this;
    }

    /**
     * Build the subject for the message.
     *
     * When using a Lettr template without an explicit subject, skip setting one
     * so the template's own subject is used instead of an auto-generated fallback.
     */
    protected function buildSubject($message): static
    {
        if ($this->templateSlug !== null && ! $this->subject) {
            return $this;
        }

        return parent::buildSubject($message);
    }

    /**
     * Get the message envelope.
     */
    public function envelope(): Envelope
    {
        return new Envelope;
    }

    /**
     * Get the message content definition.
     */
    public function content(): Content
    {
        // If Blade view is set, use view rendering
        if ($this->bladeView !== null) {
            return new Content(
                view: $this->bladeView,
                with: $this->buildViewData(),
            );
        }

        // API template mode - return empty content
        return new Content;
    }

    /**
     * Build the view data array for Blade rendering.
     *
     * @return array<string, mixed>
     */
    public function buildViewData(): array
    {
        return array_merge(parent::buildViewData(), $this->withMergeTags());
    }

    /**
     * Get the merge tags for the template.
     *
     * Override this method in subclasses to provide merge tag data.
     * This is called on every send and merged with substitutionData, which wins on conflicts.
     *
     * @return array<string, mixed>
     */
    public function withMergeTags(): array
    {
        return [];
    }

    /**
     * Build the message.
     *
     * Override this to configure the mailable - template(), substitutionData(),
     * customHeaders() and the rest. Calling parent::build() is not required: the
     * Lettr setup runs after build(), whatever build() does.
     */
    public function build(): static
    {
        return $this;
    }

    /**
     * Set the mailer that should send the message.
     *
     * Recorded when called from build(), so the Lettr setup keeps that choice
     * even when it names the mailer the mailable already had.
     *
     * @param  string  $mailer
     * @return $this
     */
    public function mailer($mailer)
    {
        if ($this->lettrPreparing) {
            $this->lettrMailerChosen = true;
        }

        return parent::mailer($mailer);
    }

    /**
     * Prepare the mailable for delivery.
     *
     * Laravel calls this before every send and render. The Lettr setup runs here,
     * after the subclass's build(), so it sees the final template, data and
     * headers no matter how - or whether - build() was overridden.
     */
    protected function prepareMailableForDelivery()
    {
        $mailerBeforeBuild = $this->mailer;
        $this->lettrPreparing = true;

        try {
            parent::prepareMailableForDelivery();
        } finally {
            $mailerChosen = $this->lettrMailerChosen;
            $this->lettrPreparing = false;
            $this->lettrMailerChosen = false;
        }

        // If using Blade view, skip Lettr-specific setup
        if ($this->bladeView !== null) {
            return;
        }

        // Route through the lettr mailer unless build() chose one (by calling
        // mailer() or assigning the property). This decides the transport only
        // where Laravel resolves it from the mailable - queued sends and sends
        // through the mail manager; Mail::mailer('x')->send() has already picked.
        if (! $mailerChosen && $this->mailer === $mailerBeforeBuild) {
            $this->mailer('lettr');
        }

        // Set placeholder HTML - the actual content comes from the Lettr template.
        // Laravel's Mailer needs some content; HTML the subclass set itself wins.
        // in_array() rather than isset(): Laravel 10-12 document $html as a
        // non-nullable string although it starts out null.
        if ($this->templateSlug !== null && in_array($this->html, [null, $this->lettrPlaceholderHtml], true)) {
            $this->lettrPlaceholderHtml = '<p>This email uses Lettr template: '.$this->templateSlug.'</p>';
            $this->html($this->lettrPlaceholderHtml);
        }
    }

    /**
     * Run the callbacks for the message, then add the Lettr headers.
     *
     * Added here rather than through withSymfonyMessage(), so each send reads the
     * mailable's current state once - no duplicates on a repeated send, and a
     * clone carries its own values rather than the original's.
     *
     * The app's own callbacks - withSymfonyMessage() and headers() - run first,
     * and a Lettr header they already set is kept, so a hand-set X-Lettr-Tag is
     * not overridden by the generated one.
     *
     * A Blade mailable sends rendered HTML, so it gets only the sending headers:
     * the tag, scheduling, idempotency and custom headers. The template headers
     * and substitution data do not apply to it.
     *
     * @param  Message  $message
     * @return $this
     */
    protected function runCallbacks($message)
    {
        parent::runCallbacks($message);

        $email = $message->getSymfonyMessage();

        if ($this->bladeView === null) {
            $this->addTemplateHeaders($email);
        }

        $this->addSendingHeaders($email);

        return $this;
    }

    /**
     * Add the headers that select a Lettr template and fill it in.
     */
    private function addTemplateHeaders(Email $message): void
    {
        if ($this->templateSlug !== null) {
            $this->addLettrHeader($message, 'X-Lettr-Template-Slug', $this->templateSlug);
        }

        if ($this->templateVersion !== null) {
            $this->addLettrHeader($message, 'X-Lettr-Template-Version', (string) $this->templateVersion);
        }

        if ($this->projectId !== null) {
            $this->addLettrHeader($message, 'X-Lettr-Project-Id', (string) $this->projectId);
        }

        // Merge tags are read fresh for every send; explicitly set data wins
        $substitutionData = array_merge($this->withMergeTags(), $this->substitutionData);

        if (count($substitutionData) > 0) {
            $this->addLettrHeader(
                $message,
                'X-Lettr-Substitution-Data',
                base64_encode(json_encode($substitutionData, JSON_THROW_ON_ERROR))
            );
        }
    }

    /**
     * Add the headers that apply to any send, with or without a template.
     */
    private function addSendingHeaders(Email $message): void
    {
        if ($this->scheduledAt !== null) {
            $this->addLettrHeader($message, 'X-Lettr-Scheduled-At', $this->scheduledAt);
        }

        if ($this->withoutIdempotency) {
            $this->addLettrHeader($message, 'X-Lettr-Idempotency', 'disabled');
        } elseif ($this->idempotencyKey !== null) {
            $this->addLettrHeader($message, 'X-Lettr-Idempotency-Key', $this->idempotencyKey);
        }

        // Add custom headers
        foreach ($this->customHeaders as $name => $value) {
            $message->getHeaders()->addTextHeader($name, $value);
        }

        // Add tag header:
        // - If tags set by user, join with _ and use that
        // - If using template slug and no tags, skip (server uses template slug as tag)
        // - If not using template slug, auto-generate from mailable class name
        if (count($this->tags) > 0) {
            $this->addLettrHeader($message, 'X-Lettr-Tag', implode('_', $this->tags));
        } elseif ($this->templateSlug === null) {
            $this->addLettrHeader($message, 'X-Lettr-Tag', $this->generateTag());
        }
    }

    /**
     * Add a Lettr header unless the app has already set it.
     */
    private function addLettrHeader(Email $message, string $name, string $value): void
    {
        if (! $message->getHeaders()->has($name)) {
            $message->getHeaders()->addTextHeader($name, $value);
        }
    }

    /**
     * Generate a tag from the mailable class name.
     * Converts "App\Mail\OrderConfirmation" to "order-confirmation".
     */
    protected function generateTag(): string
    {
        $className = class_basename(static::class);

        // Convert PascalCase to kebab-case
        $tag = strtolower(preg_replace('/(?<!^)[A-Z]/', '-$0', $className) ?? $className);

        // The API rejects tags over 64 characters, and a tag that is only there
        // for analytics must never be the reason an email fails to send.
        return rtrim(substr($tag, 0, self::MAX_GENERATED_TAG_LENGTH), '-');
    }
}
