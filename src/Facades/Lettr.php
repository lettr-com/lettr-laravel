<?php

declare(strict_types=1);

namespace Lettr\Laravel\Facades;

use Illuminate\Support\Facades\Facade;

/**
 * @method static \Lettr\Services\EmailService emails()
 * @method static \Lettr\Services\DomainService domains()
 * @method static \Lettr\Services\WebhookService webhooks()
 *
 * @see \Lettr\Lettr
 */
class Lettr extends Facade
{
    /**
     * Get the registered name of the component.
     */
    protected static function getFacadeAccessor(): string
    {
        return 'lettr';
    }
}
