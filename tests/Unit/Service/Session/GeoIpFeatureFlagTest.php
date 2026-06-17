<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Session;

use App\Service\Session\GeoIpFeatureFlag;
use PHPUnit\Framework\TestCase;

final class GeoIpFeatureFlagTest extends TestCase
{
    public function testDisabledWhenLicenseKeyEmpty(): void
    {
        $this->assertFalse(new GeoIpFeatureFlag('')->isEnabled());
    }

    public function testDisabledWhenLicenseKeyNull(): void
    {
        $this->assertFalse(new GeoIpFeatureFlag()->isEnabled());
    }

    public function testEnabledWhenLicenseKeyPresent(): void
    {
        $this->assertTrue(new GeoIpFeatureFlag('LICENSE-KEY-123')->isEnabled());
    }

    public function testWhitespaceOnlyLicenseKeyTreatedAsDisabled(): void
    {
        $this->assertFalse(new GeoIpFeatureFlag("   \t\n")->isEnabled());
    }
}
