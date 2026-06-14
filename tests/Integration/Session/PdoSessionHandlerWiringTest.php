<?php

declare(strict_types=1);

namespace App\Tests\Integration\Session;

use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\HttpFoundation\Session\Storage\Handler\PdoSessionHandler;

final class PdoSessionHandlerWiringTest extends KernelTestCase
{
    public function testSessionHandlerPdoServiceResolvesToPdoSessionHandler(): void
    {
        self::bootKernel();
        $container = self::getContainer();

        $handler = $container->get('session.handler.pdo');

        $this->assertInstanceOf(PdoSessionHandler::class, $handler);
    }
}
