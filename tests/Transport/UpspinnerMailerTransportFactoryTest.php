<?php

namespace Upspinner\ConnectBundle\Tests\Transport;

use Psr\Log\NullLogger;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\Mailer\Test\AbstractTransportFactoryTestCase;
use Symfony\Component\Mailer\Transport\Dsn;
use Symfony\Component\Mailer\Transport\TransportFactoryInterface;
use Upspinner\ConnectBundle\Transport\UpspinnerMailerTransport;
use Upspinner\ConnectBundle\Transport\UpspinnerMailerTransportFactory;

class UpspinnerMailerTransportFactoryTest extends AbstractTransportFactoryTestCase
{
    public function getFactory(): TransportFactoryInterface
    {
        return new UpspinnerMailerTransportFactory(client: new MockHttpClient(), logger: new NullLogger());
    }

    public static function supportsProvider(): iterable
    {
        yield [Dsn::fromString('upspinner://host.test?key=authKey&enmvironment=2'), true];
        yield [Dsn::fromString('somethingElse://host.test?key=authKey&enmvironment=2'), false];
    }

    public static function createProvider(): iterable
    {
        $logger = new NullLogger();

        yield [
            Dsn::fromString('upspinner://host.test?key=authKey&environment=2'),
            (new UpspinnerMailerTransport(new MockHttpClient(), null, $logger, 'authKey', '2'))->setHost('host.test'),
        ];
    }

    public static function unsupportedSchemeProvider(): iterable
    {
        yield [
            new Dsn('upspinner+foo', 'upspinner'),
            'The "upspinner+foo" scheme is not supported; supported schemes for mailer "upspinner" are: "upspinner".',
        ];
    }

    public static function incompleteDsnProvider(): iterable
    {
        yield [new Dsn('', '')];
    }
}
