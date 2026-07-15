<?php

declare(strict_types=1);

namespace App\Tests\Unit\Entity;

use App\Entity\PreviewSettings;
use PHPUnit\Framework\TestCase;

final class PreviewSettingsTest extends TestCase
{
    public function testDefaultsMatchLegacyConstants(): void
    {
        $settings = new PreviewSettings();

        $this->assertSame(1600, $settings->getLongEdge());
        $this->assertSame(85, $settings->getQuality());
    }

    public function testAcceptsAllowlistedValues(): void
    {
        $settings = new PreviewSettings();
        $settings->setLongEdge(2048);
        $settings->setQuality(90);

        $this->assertSame(2048, $settings->getLongEdge());
        $this->assertSame(90, $settings->getQuality());
    }

    public function testDefaultsAreMembersOfTheirAllowlists(): void
    {
        $this->assertContains(PreviewSettings::DEFAULT_LONG_EDGE, PreviewSettings::ALLOWED_LONG_EDGES);
        $this->assertContains(PreviewSettings::DEFAULT_QUALITY, PreviewSettings::ALLOWED_QUALITIES);
    }
}
