<?php

namespace Upspinner\ConnectBundle\Tests\Transport;

use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\Notifier\Message\ChatMessage;
use Symfony\Component\Notifier\Message\SmsMessage;
use Symfony\Component\Notifier\Test\TransportTestCase;
use Symfony\Component\Uid\UuidV4;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Symfony\Contracts\HttpClient\ResponseInterface;
use Upspinner\ConnectBundle\Transport\UpspinnerNotifierTransport;

class UpspinnerNotifierTransportTest extends TransportTestCase
{
    public static function createTransport(
        ?HttpClientInterface $client = null,
        string $from = 'from',
        string $environmentId = '721',
        string $host = 'host.test'
    ): UpspinnerNotifierTransport {
        return (new UpspinnerNotifierTransport('authkey', $from, $environmentId, $client ?? new MockHttpClient()))->setHost($host);
    }

    public static function toStringProvider(): iterable
    {
        yield ['upspinner://host.test?from=from&environment=721', self::createTransport()];
    }

    public static function supportedMessagesProvider(): iterable
    {
        yield [new SmsMessage('0611223344', 'Hello!')];
        yield [new SmsMessage('+31611223344', 'Hello!')];
    }

    public static function unsupportedMessagesProvider(): iterable
    {
        yield [new ChatMessage('Hello!')];
    }

    #[DataProvider('validFromProvider')]
    public function testNoInvalidArgumentExceptionIsThrownIfFromIsValid(string $from)
    {
        $message = new SmsMessage('+33612345678', 'Hello!');

        $response = $this->createMock(ResponseInterface::class);
        $response->expects($this->exactly(2))
            ->method('getStatusCode')
            ->willReturn(201);
        $response->expects($this->once())
            ->method('getContent')
            ->willReturn(json_encode(null, JSON_THROW_ON_ERROR));

        $client = new MockHttpClient(
            function (string $method, string $url, array $options = []) use ($response): ResponseInterface {
                $this->assertSame('POST', $method);
                $this->assertSame('https://host.test/api/incoming/sms/721', $url);

                return $response;
            }
        );

        $transport = self::createTransport($client, $from);

        $sentMessage = $transport->send($message);
        $this->assertTrue(UuidV4::isValid($sentMessage->getMessageId()));
    }

    public static function validFromProvider(): iterable
    {
        // alphanumeric sender ids
        yield ['ab'];
        yield ['abc'];
        yield ['abcd'];
        yield ['abcde'];
        yield ['abcdef'];
        yield ['abcdefg'];
        yield ['abcdefgh'];
        yield ['abcdefghi'];
        yield ['abcdefghij'];
        yield ['abcdefghijk'];
        yield ['abcdef ghij'];
        yield [' abcdefghij'];
        yield ['abcdefghij '];

        // phone numbers
        yield ['+11'];
        yield ['+112'];
        yield ['+1123'];
        yield ['+11234'];
        yield ['+112345'];
        yield ['+1123456'];
        yield ['+11234567'];
        yield ['+112345678'];
        yield ['+1123456789'];
        yield ['+11234567891'];
        yield ['+112345678912'];
        yield ['+1123456789123'];
        yield ['+11234567891234'];
        yield ['+112345678912345'];
    }
}
