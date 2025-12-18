<?php

namespace Upspinner\ConnectBundle\Transport;

use http\Exception\RuntimeException;
use Symfony\Component\Notifier\Exception\InvalidArgumentException;
use Symfony\Component\Notifier\Exception\TransportException;
use Symfony\Component\Notifier\Exception\UnsupportedMessageTypeException;
use Symfony\Component\Notifier\Message\MessageInterface;
use Symfony\Component\Notifier\Message\SentMessage;
use Symfony\Component\Notifier\Message\SmsMessage;
use Symfony\Component\Notifier\Transport\AbstractTransport;
use Symfony\Component\Uid\UuidV4;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;
use Symfony\Contracts\HttpClient\Exception\TransportExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Upspinner\ConnectBundle\Sms\UpspinnerSms;

class UpspinnerNotifierTransport extends AbstractTransport
{
    protected const HOST = '';

    private string $key;
    private string $from;
    private string $environmentId;

    public function __construct(
        string $key,
        string $from,
        string $environmentId = '',
        ?HttpClientInterface $client = null,
        ?EventDispatcherInterface $dispatcher = null
    ) {
        $this->key = $key;
        $this->from = $from;
        $this->environmentId = $environmentId;

        parent::__construct($client, $dispatcher);
    }

    public function __toString(): string
    {
        return sprintf('upspinner://%s?from=%s&environment=%s', $this->getEndpoint(), $this->from, $this->environmentId);
    }

    public function supports(MessageInterface $message): bool
    {
        return $message instanceof SmsMessage;
    }

    protected function doSend(MessageInterface $message): SentMessage
    {
        if (is_null($this->client)) {
            throw new RuntimeException('HTTP Client not instantiated');
        }

        if (!$message instanceof SmsMessage) {
            throw new UnsupportedMessageTypeException(__CLASS__, SmsMessage::class, $message);
        }

        $from = $message->getFrom() ?: $this->from;

        if (!preg_match('/^[a-zA-Z0-9\s]{2,11}$/', $from) && !preg_match('/^\+[1-9]\d{1,14}$/', $from)) {
            throw new InvalidArgumentException(
                sprintf(
                    'The "From" number "%s" is not a valid phone number, shortcode, or alphanumeric sender ID.',
                    $from
                )
            );
        }

        $endpoint = sprintf('https://%s/api/incoming/sms/%s', $this->getEndpoint(), $this->environmentId);
        $response = $this->client->request('POST', $endpoint, [
            'headers' => ['Authorization' => $this->key],
            'json' => new UpspinnerSms($from, $message->getPhone(), $message->getSubject()),
        ]);

        try {
            $statusCode = $response->getStatusCode();
        } catch (TransportExceptionInterface $e) {
            throw new TransportException('Could not reach the remote Upspinner server.', $response, 0, $e);
        }

        if (201 !== $statusCode) {
            $error = $response->toArray(false);

            throw new TransportException(
                'Unable to send the SMS: ' . $error['message'] . sprintf(' (see %s).', $error['more_info']),
                $response
            );
        }

        $sentMessage = new SentMessage($message, (string)$this);

        $messageId = (new UuidV4())->toRfc4122();
        $headers = $response->getHeaders(false);

        if (!empty($headers['x-message-id'])) {
            $messageId = $headers['x-message-id'][0];
        }

        $sentMessage->setMessageId($messageId);

        return $sentMessage;
    }
}
