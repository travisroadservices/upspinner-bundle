<?php

namespace Upspinner\ConnectBundle\Tests\Transport;

use PHPUnit\Framework\TestCase;
use ReflectionProperty;
use RuntimeException;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\JsonMockResponse;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Component\Mailer\Exception\HttpTransportException;
use Symfony\Component\Mailer\Transport\AbstractHttpTransport;
use Symfony\Component\Mime\Address;
use Symfony\Component\Mime\Email;
use Symfony\Component\Uid\UuidV4;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Symfony\Contracts\HttpClient\ResponseInterface;
use Upspinner\ConnectBundle\Email\UpspinnerEmail;
use Upspinner\ConnectBundle\Email\UpspinnerEmailAddress;
use Upspinner\ConnectBundle\Email\UpspinnerEmailContent;
use Upspinner\ConnectBundle\Transport\UpspinnerMailerTransport;

class UpspinnerMailerTransportTest extends TestCase
{
    public function testSend(): void
    {
        $email = new Email();
        $email->from(new Address('foo@example.com', 'Ms. Foo Bar'))
            ->to(new Address('bar@example.com', 'Mr. Recipient'))
            ->bcc('baz@example.com')
            ->text('content');

        $response = $this->createMock(ResponseInterface::class);

        $response
            ->expects($this->once())
            ->method('getStatusCode')
            ->willReturn(201);
        $response
            ->expects($this->once())
            ->method('getHeaders')
            ->willReturn(['x-message-id' => '1']);

        $httpClient = $this->createMock(HttpClientInterface::class);

        $httpClient
            ->expects($this->once())
            ->method('request')
            ->with(
                'POST',
                'https://localhost:8000/api/incoming/emails/1',
                [
                    'json' => new UpspinnerEmail(
                        '',
                        [new UpspinnerEmailAddress('bar@example.com', 'Mr. Recipient')],
                        [],
                        [new UpspinnerEmailAddress('baz@example.com')],
                        new UpspinnerEmailAddress('foo@example.com', 'Ms. Foo Bar'),
                        [],
                        new UpspinnerEmailContent('content', ''),
                        [],
                        [
                            'From' => '"Ms. Foo Bar" <foo@example.com>',
                            'To' => '"Mr. Recipient" <bar@example.com>',
                            'Bcc' => 'baz@example.com'
                        ]
                    ),
                    'headers' => [
                        'Authorization' => ''
                    ],
                ]
            )
            ->willReturn($response);

        $mailer = (new UpspinnerMailerTransport(client: $httpClient, environmentId: '1'))->setHost('localhost:8000');
        $mailer->send($email);
    }

    public function testToString(): void
    {
        $mailer = (new UpspinnerMailerTransport(client: new MockHttpClient()))->setHost('localhost:8000');

        $this->assertSame('upspinner://localhost:8000', (string)$mailer);
    }

    public function testSendUsesMessageIdFromResponseHeader(): void
    {
        $client = new MockHttpClient(
            new MockResponse('', ['http_code' => 201, 'response_headers' => ['x-message-id' => 'abc-123']])
        );

        $sentMessage = $this->createTransport($client)->send($this->createEmail());

        $this->assertSame('abc-123', $sentMessage?->getMessageId());
    }

    public function testSendGeneratesMessageIdWithoutResponseHeader(): void
    {
        $client = new MockHttpClient(new MockResponse('', ['http_code' => 201]));

        $sentMessage = $this->createTransport($client)->send($this->createEmail());

        $this->assertNotNull($sentMessage);
        $this->assertTrue(UuidV4::isValid($sentMessage->getMessageId()));
    }

    public function testSendThrowsOnErrorResponse(): void
    {
        $client = new MockHttpClient(new JsonMockResponse(
            ['errors' => [['message' => 'Invalid sender'], ['message' => 'Missing subject']]],
            ['http_code' => 400]
        ));

        $this->expectException(HttpTransportException::class);
        $this->expectExceptionMessage('Unable to send an email: Invalid sender; Missing subject (code 400).');

        $this->createTransport($client)->send($this->createEmail());
    }

    public function testSendThrowsOnUndecodableErrorResponse(): void
    {
        $client = new MockHttpClient(new MockResponse('Internal Server Error', ['http_code' => 500]));

        $this->expectException(HttpTransportException::class);
        $this->expectExceptionMessage('Unable to send an email: Internal Server Error (code 500).');

        $this->createTransport($client)->send($this->createEmail());
    }

    public function testSendThrowsWhenServerIsUnreachable(): void
    {
        $client = new MockHttpClient(new MockResponse('', ['error' => 'Connection refused']));

        $this->expectException(HttpTransportException::class);
        $this->expectExceptionMessage('Could not reach the remote Upspinner server.');

        $this->createTransport($client)->send($this->createEmail());
    }

    public function testSendThrowsWithoutHttpClient(): void
    {
        $mailer = $this->createTransport(new MockHttpClient());
        (new ReflectionProperty(AbstractHttpTransport::class, 'client'))->setValue($mailer, null);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('HTTP Client not instantiated');

        $mailer->send($this->createEmail());
    }

    public function testSendIncludesAttachments(): void
    {
        $payload = null;
        $client = new MockHttpClient(
            function (string $method, string $url, array $options) use (&$payload): MockResponse {
                $payload = json_decode($options['body'], true, flags: JSON_THROW_ON_ERROR);

                return new MockResponse('', ['http_code' => 201]);
            }
        );

        $email = $this->createEmail()
            ->html('<img src="cid:logo.png">')
            ->attach('file contents', 'report.txt', 'text/plain')
            ->embed('image bytes', 'logo.png', 'image/png');

        $this->createTransport($client)->send($email);

        $this->assertSame('<img src="cid:logo.png">', $payload['content']['html']);
        $this->assertSame([
            [
                'disposition' => 'attachment',
                'content' => base64_encode('file contents'),
                'type' => 'text/plain; name=report.txt',
                'filename' => 'report.txt',
                'contentId' => '',
            ],
            [
                'disposition' => 'inline',
                'content' => base64_encode('image bytes'),
                'type' => 'image/png; name=logo.png',
                'filename' => 'logo.png',
                'contentId' => 'logo.png',
            ],
        ], $payload['attachments']);
    }

    private function createTransport(MockHttpClient $client): UpspinnerMailerTransport
    {
        return (new UpspinnerMailerTransport(client: $client, key: 'authkey', environmentId: '1'))
            ->setHost('localhost:8000');
    }

    private function createEmail(): Email
    {
        return (new Email())
            ->from('foo@example.com')
            ->to('bar@example.com')
            ->subject('Subject')
            ->text('content');
    }
}
