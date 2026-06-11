<?php

declare(strict_types=1);

namespace App\Tests\Unit\Twig;

use App\Twig\BytesExtension;
use PHPUnit\Framework\TestCase;

final class BytesExtensionTest extends TestCase
{
    private BytesExtension $ext;

    protected function setUp(): void
    {
        $this->ext = new BytesExtension();
    }

    public function testZeroBytes(): void
    {
        $this->assertSame('0 B', $this->ext->formatBytes(0));
    }

    public function testUnderOneKilobyteReturnsBytes(): void
    {
        $this->assertSame('512 B', $this->ext->formatBytes(512));
    }

    public function testExactlyOneKilobyte(): void
    {
        $this->assertSame('1.0 KB', $this->ext->formatBytes(1024));
    }

    public function testKilobytes(): void
    {
        $this->assertSame('1.5 KB', $this->ext->formatBytes(1536));
    }

    public function testMegabytes(): void
    {
        $this->assertSame('2.1 MB', $this->ext->formatBytes(2_202_010));
    }

    public function testGigabytes(): void
    {
        $this->assertSame('1.0 GB', $this->ext->formatBytes(1_073_741_824));
    }
}
