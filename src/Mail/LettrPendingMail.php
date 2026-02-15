<?php

declare(strict_types=1);

namespace Lettr\Laravel\Mail;

use Illuminate\Contracts\Mail\Mailer as MailerContract;
use Illuminate\Contracts\Support\Arrayable;
use Illuminate\Mail\PendingMail;
use Illuminate\Mail\SentMessage;

/**
 * Extended PendingMail that supports Lettr template sending.
 */
class LettrPendingMail extends PendingMail
{
    public function __construct(MailerContract $mailer)
    {
        parent::__construct($mailer);
    }

    /**
     * Send a Lettr template.
     *
     * @param  array<string, mixed>|Arrayable<string, mixed>  $substitutionData
     * @param  string|null  $tag  Override the default tag (template slug). If null, server uses template slug.
     * @param  string|null  $subject  Override the template's default subject line.
     */
    public function sendTemplate(
        string $templateSlug,
        ?string $subject = null,
        array|Arrayable $substitutionData = [],
        ?int $version = null,
        ?string $tag = null,
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
        );

        return $this->send($mailable);
    }
}
