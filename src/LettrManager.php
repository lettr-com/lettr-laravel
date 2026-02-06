<?php

declare(strict_types=1);

namespace Lettr\Laravel;

use Closure;
use Lettr\Laravel\Services\TemplateServiceWrapper;
use Lettr\Lettr;
use Lettr\Services\DomainService;
use Lettr\Services\EmailService;
use Lettr\Services\HealthService;
use Lettr\Services\ProjectService;
use Lettr\Services\WebhookService;

/**
 * Manager class that wraps the Lettr SDK and provides Laravel-specific service wrappers.
 *
 * @property-read EmailService $emails
 * @property-read DomainService $domains
 * @property-read ProjectService $projects
 * @property-read WebhookService $webhooks
 * @property-read TemplateServiceWrapper $templates
 */
class LettrManager
{
    private ?Lettr $resolvedLettr = null;

    private ?TemplateServiceWrapper $templateServiceWrapper = null;

    /**
     * @param  Closure(): Lettr  $lettrResolver
     */
    public function __construct(
        private readonly Closure $lettrResolver,
        private readonly ?int $defaultProjectId = null,
    ) {}

    /**
     * Get the lazily-resolved Lettr SDK instance.
     */
    protected function lettr(): Lettr
    {
        if ($this->resolvedLettr === null) {
            $this->resolvedLettr = ($this->lettrResolver)();
        }

        return $this->resolvedLettr;
    }

    /**
     * Get the email service.
     */
    public function emails(): EmailService
    {
        return $this->lettr()->emails();
    }

    /**
     * Get the domain service.
     */
    public function domains(): DomainService
    {
        return $this->lettr()->domains();
    }

    /**
     * Get the project service.
     */
    public function projects(): ProjectService
    {
        return $this->lettr()->projects();
    }

    /**
     * Get the webhook service.
     */
    public function webhooks(): WebhookService
    {
        return $this->lettr()->webhooks();
    }

    /**
     * Get the health service.
     */
    public function health(): HealthService
    {
        return $this->lettr()->health();
    }

    /**
     * Get the template service wrapper with default project ID support.
     */
    public function templates(): TemplateServiceWrapper
    {
        if ($this->templateServiceWrapper === null) {
            $this->templateServiceWrapper = new TemplateServiceWrapper(
                $this->lettr()->templates(),
                $this->defaultProjectId,
            );
        }

        return $this->templateServiceWrapper;
    }

    /**
     * Get the underlying Lettr SDK instance.
     */
    public function sdk(): Lettr
    {
        return $this->lettr();
    }

    /**
     * Magic method to access services as properties.
     */
    public function __get(string $name): mixed
    {
        return match ($name) {
            'emails' => $this->emails(),
            'domains' => $this->domains(),
            'projects' => $this->projects(),
            'webhooks' => $this->webhooks(),
            'templates' => $this->templates(),
            'health' => $this->health(),
            default => throw new \InvalidArgumentException("Unknown service: {$name}"),
        };
    }
}
