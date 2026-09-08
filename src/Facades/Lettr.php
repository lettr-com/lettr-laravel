<?php

declare(strict_types=1);

namespace Lettr\Laravel\Facades;

use Illuminate\Support\Facades\Facade;
use Lettr\Laravel\LettrManager;

/**
 * @method static \Lettr\Services\EmailService emails()
 * @method static \Lettr\Services\DomainService domains()
 * @method static \Lettr\Services\ProjectService projects()
 * @method static \Lettr\Services\FolderService folders()
 * @method static \Lettr\Services\WebhookService webhooks()
 * @method static \Lettr\Services\HealthService health()
 * @method static \Lettr\Services\AudienceService audience()
 * @method static \Lettr\Services\CampaignService campaigns()
 * @method static \Lettr\Laravel\Services\TemplateServiceWrapper templates()
 *
 * @see \Lettr\Lettr
 * @see LettrManager
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
