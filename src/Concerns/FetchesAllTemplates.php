<?php

declare(strict_types=1);

namespace Lettr\Laravel\Concerns;

use Lettr\Dto\Template\ListTemplatesFilter;
use Lettr\Dto\Template\Template;
use Lettr\Laravel\LettrManager;

/**
 * Lists every template on the account, following pagination.
 *
 * @property-read LettrManager $lettr
 */
trait FetchesAllTemplates
{
    use ThrottlesApiRequests;

    /**
     * @return array<int, Template>
     */
    protected function fetchAllTemplates(): array
    {
        $templates = [];
        $page = 1;

        do {
            $response = $this->withRateLimitRetry(
                fn () => $this->lettr->templates()->list(new ListTemplatesFilter(perPage: 100, page: $page))
            );

            array_push($templates, ...$response->templates->all());
            $page++;
        } while ($response->hasMore());

        return $templates;
    }
}
