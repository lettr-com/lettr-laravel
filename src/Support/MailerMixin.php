<?php

declare(strict_types=1);

namespace Lettr\Laravel\Support;

use Lettr\Laravel\Mail\LettrPendingMail;

/**
 * @mixin \Illuminate\Mail\Mailer
 */
class MailerMixin
{
    /**
     * Start a Lettr mail chain.
     */
    public function lettr(): LettrPendingMail
    {
        /** @phpstan-ignore-next-line */
        return new LettrPendingMail($this);
    }
}
