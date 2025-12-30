<?php

namespace Upspinner\ConnectBundle\Transport;

use http\Exception\RuntimeException;
use Psr\EventDispatcher\EventDispatcherInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Mailer\Envelope;
use Symfony\Component\Mailer\Exception\HttpTransportException;
use Symfony\Component\Mailer\Exception\TransportExceptionInterface;
use Symfony\Component\Mailer\SentMessage;
use Symfony\Component\Mailer\Transport\AbstractApiTransport;
use Symfony\Component\Mime\Address;
use Symfony\Component\Mime\Email;
use Symfony\Component\Uid\UuidV4;
use Symfony\Contracts\HttpClient\Exception\ClientExceptionInterface;
use Symfony\Contracts\HttpClient\Exception\DecodingExceptionInterface;
use Symfony\Contracts\HttpClient\Exception\RedirectionExceptionInterface;
use Symfony\Contracts\HttpClient\Exception\ServerExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Symfony\Contracts\HttpClient\ResponseInterface;
use Upspinner\ConnectBundle\Email\UpspinnerEmail;
use Upspinner\ConnectBundle\Email\UpspinnerEmailAddress;
use Upspinner\ConnectBundle\Email\UpspinnerEmailAttachments;
use Upspinner\ConnectBundle\Email\UpspinnerEmailContent;

class UpspinnerMailerTransport extends AbstractApiTransport
{
    private const HOST = '';
    private const PATH = '/api/incoming/emails';

    private string $key = '';
    private string $environmentId = '';

    public function __construct(
        ?HttpClientInterface $client = null,
        ?EventDispatcherInterface $dispatcher = null,
        ?LoggerInterface $logger = null,
        string $key = '',
        string $environmentId = ''
    ) {
        parent::__construct($client, $dispatcher, $logger);

        $this->key = $key;
        $this->environmentId = $environmentId;
    }

    /**
     * @return string
     */
    public function __toString(): string
    {
        return sprintf('upspinner://%s', $this->getEndpoint());
    }

    /**
     * @param SentMessage $sentMessage
     * @param Email $email
     * @param Envelope $envelope
     * @return ResponseInterface
     * @throws ClientExceptionInterface
     * @throws RedirectionExceptionInterface
     * @throws ServerExceptionInterface
     * @throws \Symfony\Contracts\HttpClient\Exception\TransportExceptionInterface
     */
    protected function doSendApi(SentMessage $sentMessage, Email $email, Envelope $envelope): ResponseInterface
    {
        if (is_null($this->client)) {
            throw new RuntimeException('HTTP Client not instantiated');
        }
        $response = $this->client->request(
            'POST',
            'https://' . $this->getEndpoint() . self::PATH . '/' . $this->environmentId,
            [
                'json' => $this->getPayload($email, $envelope),
                'headers' => ['Authorization' => $this->key]
            ]
        );

        try {
            $statusCode = $response->getStatusCode();
        } catch (TransportExceptionInterface $e) {
            throw new HttpTransportException('Could not reach the remote Upspinner server.', $response, 0, $e);
        }

        if (201 !== $statusCode) {
            try {
                $result = $response->toArray(false);

                throw new HttpTransportException(
                    'Unable to send an email: ' . implode('; ', array_column($result['errors'], 'message')) . sprintf(
                        ' (code %d).',
                        $statusCode
                    ),
                    $response
                );
            } catch (DecodingExceptionInterface $e) {
                throw new HttpTransportException(
                    'Unable to send an email: ' . $response->getContent(false) . sprintf(' (code %d).', $statusCode),
                    $response,
                    0,
                    $e
                );
            }
        }

        $messageId = (new UuidV4())->toRfc4122();
        $headers = $response->getHeaders(false);

        if (!empty($headers['x-message-id'])) {
            $messageId = $headers['x-message-id'][0];
        }

        $sentMessage->setMessageId($messageId);

        return $response;
    }

    /**
     * @param Email $email
     * @param Envelope $envelope
     * @return UpspinnerEmail
     */
    private function getPayload(Email $email, Envelope $envelope): UpspinnerEmail
    {
        $addressParser = static function (Address $address) {
            return new UpspinnerEmailAddress($address->getAddress(), $address->getName());
        };

        $headers = [];
        foreach ($email->getHeaders()->all() as $name => $header) {
            $headers[(string)$header->getName()] = $header->getBodyAsString();
        }

        return new UpspinnerEmail(
            $email->getSubject() ?? '',
            array_map($addressParser, $this->getRecipients($email, $envelope)),
            array_map($addressParser, $email->getCc()),
            array_map($addressParser, $email->getBcc()),
            $addressParser($envelope->getSender()),
            array_map($addressParser, $email->getReplyTo()),
            $this->getContent($email),
            $this->getAttachments($email),
            $headers,
        );
    }

    /**
     * @param Email $email
     * @return UpspinnerEmailContent
     */
    private function getContent(Email $email): UpspinnerEmailContent
    {
        $contentText = $contentHtml = '';
        if (null !== $text = $email->getTextBody()) {
            $contentText = (string)$text;
        }
        if (null !== $html = $email->getHtmlBody()) {
            $contentHtml = (string)$html;
        }

        return new UpspinnerEmailContent($contentText, $contentHtml);
    }

    /**
     * @param Email $email
     * @return array<UpspinnerEmailAttachments>
     */
    private function getAttachments(Email $email): array
    {
        $attachments = [];
        foreach ($email->getAttachments() as $attachment) {
            $headers = $attachment->getPreparedHeaders();
            $filename = $headers->getHeaderParameter('Content-Disposition', 'filename') ?? '';
            /** @var string $disposition */
            $disposition = $headers->getHeaderBody('Content-Disposition');
            $contentId = '';

            if ('inline' === $disposition) {
                $contentId = $filename;
            }

            $attachments[] = new UpspinnerEmailAttachments(
                $disposition,
                str_replace("\r\n", '', $attachment->bodyToString()),
                $headers->get('Content-Type')?->getBodyAsString() ?? '',
                $filename,
                $contentId
            );
        }

        return $attachments;
    }

    private function getEndpoint(): string
    {
        return ($this->host ?: self::HOST) . ($this->port ? ':' . $this->port : '');
    }
}
