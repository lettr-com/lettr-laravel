<?php

declare(strict_types=1);

namespace Lettr\Laravel\Mail;

use DateTimeInterface;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

abstract class LettrMailable extends Mailable
{
    use Queueable;
    use SerializesModels;

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
     * This is automatically called during build() and merged with substitutionData.
     *
     * @return array<string, mixed>
     */
    public function withMergeTags(): array
    {
        return [];
    }

    /**
     * Build the message.
     */
    public function build(): static
    {
        // If using Blade view, skip Lettr-specific setup
        if ($this->bladeView !== null) {
            return $this;
        }

        // Use the lettr mailer/transport for API template mode
        $this->mailer('lettr');

        // Merge data from withMergeTags() with any manually set substitution data
        $mergeTags = $this->withMergeTags();
        if (! empty($mergeTags)) {
            $this->substitutionData = array_merge($mergeTags, $this->substitutionData);
        }

        // Set placeholder HTML - the actual content comes from the Lettr template
        // This is required because Laravel's Mailer expects some content
        if ($this->templateSlug !== null) {
            $this->html('<p>This email uses Lettr template: '.$this->templateSlug.'</p>');
        }

        // Register callback to add Lettr headers
        $this->withSymfonyMessage(function ($message): void {
            if ($this->templateSlug !== null) {
                $message->getHeaders()->addTextHeader('X-Lettr-Template-Slug', $this->templateSlug);
            }

            if ($this->templateVersion !== null) {
                $message->getHeaders()->addTextHeader('X-Lettr-Template-Version', (string) $this->templateVersion);
            }

            if ($this->projectId !== null) {
                $message->getHeaders()->addTextHeader('X-Lettr-Project-Id', (string) $this->projectId);
            }

            if (count($this->substitutionData) > 0) {
                $message->getHeaders()->addTextHeader(
                    'X-Lettr-Substitution-Data',
                    base64_encode(json_encode($this->substitutionData, JSON_THROW_ON_ERROR))
                );
            }

            if ($this->scheduledAt !== null) {
                $message->getHeaders()->addTextHeader('X-Lettr-Scheduled-At', $this->scheduledAt);
            }

            if ($this->withoutIdempotency) {
                $message->getHeaders()->addTextHeader('X-Lettr-Idempotency', 'disabled');
            } elseif ($this->idempotencyKey !== null) {
                $message->getHeaders()->addTextHeader('X-Lettr-Idempotency-Key', $this->idempotencyKey);
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
                $message->getHeaders()->addTextHeader('X-Lettr-Tag', implode('_', $this->tags));
            } elseif ($this->templateSlug === null) {
                $message->getHeaders()->addTextHeader('X-Lettr-Tag', $this->generateTag());
            }
        });

        return $this;
    }

    /**
     * Generate a tag from the mailable class name.
     * Converts "App\Mail\OrderConfirmation" to "order-confirmation".
     */
    protected function generateTag(): string
    {
        $className = class_basename(static::class);

        // Convert PascalCase to kebab-case
        return strtolower(preg_replace('/(?<!^)[A-Z]/', '-$0', $className) ?? $className);
    }
}
