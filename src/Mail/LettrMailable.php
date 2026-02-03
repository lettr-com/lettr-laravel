<?php

declare(strict_types=1);

namespace Lettr\Laravel\Mail;

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
        });

        return $this;
    }
}
