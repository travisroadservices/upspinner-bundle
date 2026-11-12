<?php

namespace Upspinner\ConnectBundle\Tests\DependencyInjection;

use PHPUnit\Framework\TestCase;
use Symfony\Component\DependencyInjection\ChildDefinition;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Upspinner\ConnectBundle\DependencyInjection\UpspinnerConnectExtension;
use Upspinner\ConnectBundle\Transport\UpspinnerMailerTransportFactory;
use Upspinner\ConnectBundle\Transport\UpspinnerNotifierTransportFactory;

class UpspinnerConnectExtensionTest extends TestCase
{
    public function testLoadRegistersMailerTransportFactory(): void
    {
        $container = $this->loadExtension();

        $this->assertTrue($container->hasDefinition(UpspinnerMailerTransportFactory::class));
        $this->assertTrue(
            $container->getDefinition(UpspinnerMailerTransportFactory::class)->hasTag('mailer.transport_factory')
        );
    }

    public function testLoadRegistersNotifierTransportFactory(): void
    {
        $container = $this->loadExtension();

        $this->assertTrue($container->hasDefinition(UpspinnerNotifierTransportFactory::class));

        $definition = $container->getDefinition(UpspinnerNotifierTransportFactory::class);
        $this->assertInstanceOf(ChildDefinition::class, $definition);
        $this->assertSame('notifier.transport_factory.abstract', $definition->getParent());
        $this->assertTrue($definition->hasTag('texter.transport_factory'));
    }

    private function loadExtension(): ContainerBuilder
    {
        $container = new ContainerBuilder();
        (new UpspinnerConnectExtension())->load([], $container);

        return $container;
    }
}
