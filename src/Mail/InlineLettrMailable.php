<?php

declare(strict_types=1);

namespace Lettr\Laravel\Mail;

/**
 * A concrete LettrMailable for inline template sending.
 *
 * @internal Used by LettrPendingMail::sendTemplate()
 */
final class InlineLettrMailable extends LettrMailable
{
    /**
     * @param  array<string, mixed>  $substitutionData
     */
    public function __construct(
        string $templateSlug,
        ?string $subject = null,
        array $substitutionData = [],
        ?int $version = null,
        ?string $tag = null,
    ) {
        $this->template($templateSlug, $version);
        $this->substitutionData($substitutionData);

        if ($tag !== null) {
            parent::tag($tag);
        }

        if ($subject !== null) {
            $this->subject = $subject;
        }
    }

    public function build(): static
    {
        return parent::build();
    }
}
