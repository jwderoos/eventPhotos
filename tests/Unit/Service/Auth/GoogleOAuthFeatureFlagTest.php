<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Auth;

use App\Service\Auth\GoogleOAuthFeatureFlag;
use PHPUnit\Framework\TestCase;

final class GoogleOAuthFeatureFlagTest extends TestCase
{
    public function testDisabledWhenClientIdNull(): void
    {
        $this->assertFalse(new GoogleOAuthFeatureFlag()->isEnabled());
    }

    public function testDisabledWhenClientIdEmpty(): void
    {
        $this->assertFalse(new GoogleOAuthFeatureFlag('')->isEnabled());
    }

    public function testDisabledWhenClientIdOnlyWhitespace(): void
    {
        $this->assertFalse(new GoogleOAuthFeatureFlag('   ')->isEnabled());
    }

    public function testEnabledWhenClientIdSet(): void
    {
        $this->assertTrue(new GoogleOAuthFeatureFlag('123.apps.googleusercontent.com')->isEnabled());
    }
}
