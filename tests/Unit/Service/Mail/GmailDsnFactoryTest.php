<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Mail;

use App\Service\Mail\GmailDsnFactory;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Mailer\Transport\Dsn;

final class GmailDsnFactoryTest extends TestCase
{
    public function testAssemblesAndParsesBack(): void
    {
        $dsn = new GmailDsnFactory()->build('name@gmail.com', 'abcd efgh ijkl mnop');
        $parsed = Dsn::fromString($dsn);

        $this->assertSame('smtps', $parsed->getScheme());
        $this->assertSame('smtp.gmail.com', $parsed->getHost());
        $this->assertSame(465, $parsed->getPort());
        $this->assertSame('name@gmail.com', $parsed->getUser());
        $this->assertSame('abcdefghijklmnop', $parsed->getPassword());
    }

    public function testUrlSignificantCharsAreEncoded(): void
    {
        // App passwords are alphanumeric in practice, but the encoder must be robust:
        // an address/password containing ':' '@' '/' must round-trip exactly.
        $dsn = new GmailDsnFactory()->build('o+tag@gmail.com', 'a:b@c/d');
        $parsed = Dsn::fromString($dsn);

        $this->assertSame('o+tag@gmail.com', $parsed->getUser());
        $this->assertSame('a:b@c/d', $parsed->getPassword());
        $this->assertSame('smtp.gmail.com', $parsed->getHost());
    }
}
