<?php

declare(strict_types=1);

namespace Lettr\Laravel\Transport;

use Exception;
use Lettr\Builders\EmailBuilder;
use Lettr\Dto\Email\Attachment;
use Lettr\Lettr;
use Symfony\Component\Mailer\Exception\TransportException;
use Symfony\Component\Mailer\SentMessage;
use Symfony\Component\Mailer\Transport\AbstractTransport;
use Symfony\Component\Mime\Address;
use Symfony\Component\Mime\Email;
use Symfony\Component\Mime\Message;
use Symfony\Component\Mime\MessageConverter;
use Symfony\Component\Mime\Part\DataPart;

class LettrTransportFactory extends AbstractTransport
{
    /**
     * Create a new Lettr transport instance.
     *
     * @param  array<string, mixed>  $config
     */
    public function __construct(
        protected Lettr $lettr,
        protected array $config = []
    ) {
        parent::__construct();
    }

    /**
     * {@inheritDoc}
     */
    protected function doSend(SentMessage $message): void
    {
        $originalMessage = $message->getOriginalMessage();

        if (! $originalMessage instanceof Message) {
            throw new TransportException('The message must be an instance of '.Message::class);
        }

        $email = MessageConverter::toEmail($originalMessage);
        $envelope = $message->getEnvelope();

        try {
            $builder = $this->lettr->emails()->create()
                ->from($envelope->getSender()->getAddress(), $envelope->getSender()->getName())
                ->to($this->stringifyAddresses($this->getToRecipients($email)))
                ->subject($email->getSubject() ?? '');

            // Check for Lettr template headers
            $templateSlug = $this->getHeader($email, 'X-Lettr-Template-Slug');

            if ($templateSlug !== null) {
                $this->configureTemplate($builder, $email, $templateSlug);
            } else {
                $this->configureContent($builder, $email);
            }

            // Add CC recipients
            $cc = $email->getCc();
            if (count($cc) > 0) {
                $builder->cc($this->stringifyAddresses($cc));
            }

            // Add BCC recipients
            $bcc = $email->getBcc();
            if (count($bcc) > 0) {
                $builder->bcc($this->stringifyAddresses($bcc));
            }

            // Add Reply-To
            $replyTo = $email->getReplyTo();
            if (count($replyTo) > 0) {
                $builder->replyTo($replyTo[0]->getAddress());
            }

            // Add attachments
            foreach ($email->getAttachments() as $attachment) {
                if (! $attachment instanceof DataPart) {
                    continue;
                }

                $builder->attach(
                    Attachment::fromBinary(
                        $attachment->getBody(),
                        $attachment->getFilename() ?? 'attachment',
                        $attachment->getContentType()
                    )
                );
            }

            $result = $this->lettr->emails()->send($builder);
        } catch (Exception $exception) {
            throw new TransportException(
                sprintf('Request to the Lettr API failed. Reason: %s', $exception->getMessage()),
                is_int($exception->getCode()) ? $exception->getCode() : 0,
                $exception
            );
        }

        $email->getHeaders()->addHeader('X-Lettr-Request-ID', (string) $result->requestId);
    }

    /**
     * Configure the builder with template settings.
     */
    protected function configureTemplate(EmailBuilder $builder, Email $email, string $templateSlug): void
    {
        $templateVersion = $this->getHeader($email, 'X-Lettr-Template-Version');
        $projectId = $this->getHeader($email, 'X-Lettr-Project-Id');

        $builder->useTemplate(
            $templateSlug,
            $templateVersion !== null ? (int) $templateVersion : null,
            $projectId !== null ? (int) $projectId : null
        );

        // Get substitution data from header
        $substitutionDataHeader = $this->getHeader($email, 'X-Lettr-Substitution-Data');
        if ($substitutionDataHeader !== null) {
            $decoded = base64_decode($substitutionDataHeader, true);
            if ($decoded !== false) {
                /** @var array<string, mixed> $substitutionData */
                $substitutionData = json_decode($decoded, true);
                if (is_array($substitutionData)) {
                    $builder->substitutionData($substitutionData);
                }
            }
        }
    }

    /**
     * Configure the builder with HTML/text content.
     */
    protected function configureContent(EmailBuilder $builder, Email $email): void
    {
        // Add text content
        $textBody = $email->getTextBody();
        if (is_string($textBody)) {
            $builder->text($textBody);
        }

        // Add HTML content
        $htmlBody = $email->getHtmlBody();
        if (is_string($htmlBody)) {
            $builder->html($htmlBody);
        } elseif (is_resource($htmlBody)) {
            $contents = stream_get_contents($htmlBody);
            if (is_string($contents)) {
                $builder->html($contents);
            }
        }
    }

    /**
     * Get a header value from the email.
     */
    protected function getHeader(Email $email, string $name): ?string
    {
        $header = $email->getHeaders()->get($name);

        if ($header === null) {
            return null;
        }

        return $header->getBodyAsString();
    }

    /**
     * Get the TO recipients (excluding CC and BCC).
     *
     * @return array<Address>
     */
    protected function getToRecipients(Email $email): array
    {
        return $email->getTo();
    }

    /**
     * Convert Address objects to string array.
     *
     * @param  array<Address>  $addresses
     * @return array<string>
     */
    protected function stringifyAddresses(array $addresses): array
    {
        return array_map(
            static fn (Address $address): string => $address->getAddress(),
            $addresses
        );
    }

    /**
     * Get the string representation of the transport.
     */
    public function __toString(): string
    {
        return 'lettr';
    }
}
