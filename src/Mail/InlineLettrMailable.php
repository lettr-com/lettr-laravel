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
     * @param  array<string, string>  $customHeaders
     */
    public function __construct(
        string $templateSlug,
        ?string $subject = null,
        array $substitutionData = [],
        ?int $version = null,
        ?string $tag = null,
        array $customHeaders = [],
        ?string $scheduledAt = null,
    ) {
        $this->template($templateSlug, $version);
        $this->substitutionData($substitutionData);

        if ($tag !== null) {
            parent::tag($tag);
        }

        if ($subject !== null) {
            $this->subject = $subject;
        }

        if (count($customHeaders) > 0) {
            $this->customHeaders($customHeaders);
        }

        if ($scheduledAt !== null) {
            $this->scheduledAt($scheduledAt);
        }
    }
}
