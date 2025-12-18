<?php

namespace Upspinner\ConnectBundle\Tests\Transport;

use Symfony\Component\Notifier\Test\AbstractTransportFactoryTestCase;
use Symfony\Component\Notifier\Transport\TransportFactoryInterface;
use Upspinner\ConnectBundle\Transport\UpspinnerNotifierTransportFactory;

class UpspinnerNotifierTransportFactoryTest extends AbstractTransportFactoryTestCase
{
    public function createFactory(): TransportFactoryInterface
    {
        return new UpspinnerNotifierTransportFactory();
    }

    public static function supportsProvider(): iterable
    {
        yield [true, 'upspinner://:authKey@default?from=0611223344&environment=2'];
        yield [false, 'somethingElse://:authKey@default?from=0611223344'];
    }

    public static function createProvider(): iterable
    {
        yield [
            'upspinner://host.test?from=0611223344&environment=2',
            'upspinner://:authKey@host.test?from=0611223344&environment=2',
        ];
    }
    public static function unsupportedSchemeProvider(): iterable
    {
        yield ['somethingElse://:authKey@default?from=0611223344'];
        yield ['somethingElse://:authKey@default']; // missing "from" option
    }

    public static function missingRequiredOptionProvider(): iterable
    {
        yield 'missing option: from' => ['upspinner://:authKey@default'];
    }

    public static function incompleteDsnProvider(): iterable
    {
        yield 'missing option: from' => ['upspinner://:authKey@default'];
    }
}
