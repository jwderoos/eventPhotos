<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Session;

use App\Service\Session\UserAgentParser;
use PHPUnit\Framework\TestCase;

final class UserAgentParserTest extends TestCase
{
    public function testReturnsNullForEmptyUa(): void
    {
        $this->assertNull(new UserAgentParser()->displayString(''));
    }

    public function testParsesCommonDesktopChrome(): void
    {
        $ua = 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7)'
            . ' AppleWebKit/537.36 (KHTML, like Gecko) Chrome/124.0.0.0 Safari/537.36';
        $result = new UserAgentParser()->displayString($ua);
        $this->assertNotNull($result);
        $this->assertStringContainsStringIgnoringCase('chrome', $result);
        $this->assertStringContainsStringIgnoringCase('mac', $result);
    }

    public function testTruncatesToOneHundredTwentyEightChars(): void
    {
        $longUa = 'Mozilla/5.0 ' . str_repeat('X', 1024);
        $result = new UserAgentParser()->displayString($longUa);
        $this->assertNotNull($result);
        $this->assertLessThanOrEqual(128, strlen($result));
    }
}
