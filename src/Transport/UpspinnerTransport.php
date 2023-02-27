<?php

namespace Upspinner\MailerBundle\Transport;

use App\Service\Upspinner;
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
use Symfony\Contracts\HttpClient\Exception\DecodingExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Symfony\Contracts\HttpClient\ResponseInterface;
use Upspinner\MailerBundle\Email\UpspinnerEmail;
use Upspinner\MailerBundle\Email\UpspinnerEmailAddress;
use Upspinner\MailerBundle\Email\UpspinnerEmailAttachments;
use Upspinner\MailerBundle\Email\UpspinnerEmailContent;
use Upspinner\MailerBundle\Email\UpspinnerEmailPersonalization;

class UpspinnerTransport extends AbstractApiTransport
{
    private const HOST = '';
    private const PATH = '/api/incoming/emails';

    private string $key = '';

    public function __construct(
        HttpClientInterface $client = null,
        EventDispatcherInterface $dispatcher = null,
        LoggerInterface $logger = null,
        string $host = '',
        string $key = ''
    ) {
        parent::__construct($client, $dispatcher, $logger);

        $this->host = $host;
        $this->key = $key;
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
     * @throws \Symfony\Contracts\HttpClient\Exception\ClientExceptionInterface
     * @throws \Symfony\Contracts\HttpClient\Exception\RedirectionExceptionInterface
     * @throws \Symfony\Contracts\HttpClient\Exception\ServerExceptionInterface
     * @throws \Symfony\Contracts\HttpClient\Exception\TransportExceptionInterface
     */
    protected function doSendApi(SentMessage $sentMessage, Email $email, Envelope $envelope): ResponseInterface
    {
        $response = $this->client->request('POST', 'https://' . $this->getEndpoint() . self::PATH, [
            'json' => $this->getPayload($email, $envelope),
            'headers' => ['Authorization' => $this->key]
        ]);

        try {
            $statusCode = $response->getStatusCode();
        } catch (TransportExceptionInterface $e) {
            throw new HttpTransportException('Could not reach the remote Upspinner server.', $response, 0, $e);
        }

        if (200 !== $statusCode) {
            try {
                $result = $response->toArray(false);

                throw new HttpTransportException(
                    'Unable to send an email: ' . implode('; ', array_column($result['errors'], 'message')) . sprintf(
                        ' (code %d).',
                        $statusCode
                    ), $response
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
        if (!empty($response->getHeaders(false)['x-message-id'])) {
            $messageId = $response->getHeaders(false)['x-message-id'][0];
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
            new UpspinnerEmailPersonalization(
                $email->getSubject() ?? '',
                array_map($addressParser, $this->getRecipients($email, $envelope)),
                array_map($addressParser, $email->getCc()),
                array_map($addressParser, $email->getBcc()),
            ),
            $addressParser($envelope->getSender()),
            array_map($addressParser, $email->getReplyTo()),
            $this->getContent($email),
            $this->getAttachments($email),
            $headers,
        );
    }

    /**
     * @param Email $email
     * @return array<UpspinnerEmailContent>
     */
    private function getContent(Email $email): array
    {
        $content = [];
        if (null !== $text = $email->getTextBody()) {
            $content[] = new UpspinnerEmailContent('text/plain', $text);
        }
        if (null !== $html = $email->getHtmlBody()) {
            $content[] = new UpspinnerEmailContent('text/html', $html);
        }

        return $content;
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