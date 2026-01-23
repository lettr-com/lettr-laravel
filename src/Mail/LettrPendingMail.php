<?php

declare(strict_types=1);

namespace Lettr\Laravel\Mail;

use Illuminate\Contracts\Mail\Mailer as MailerContract;
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
     * @param  array<string, mixed>  $substitutionData
     */
    public function sendTemplate(
        string $templateSlug,
        array $substitutionData = [],
        ?int $version = null,
        ?int $projectId = null,
    ): ?SentMessage {
        $mailable = new InlineLettrMailable(
            $templateSlug,
            $substitutionData,
            $version,
            $projectId,
        );

        return $this->send($mailable);
    }
}
