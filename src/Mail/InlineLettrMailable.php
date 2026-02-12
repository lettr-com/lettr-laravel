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
        array $substitutionData = [],
        ?int $version = null,
        ?int $projectId = null,
        ?string $tag = null,
    ) {
        $this->template($templateSlug, $version, $projectId);
        $this->substitutionData($substitutionData);

        if ($tag !== null) {
            parent::tag($tag);
        }
    }

    public function build(): static
    {
        return parent::build();
    }
}
