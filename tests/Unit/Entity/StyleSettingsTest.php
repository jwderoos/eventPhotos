<?php

declare(strict_types=1);

namespace App\Tests\Unit\Entity;

use App\Entity\StyleSettings;
use PHPUnit\Framework\TestCase;

final class StyleSettingsTest extends TestCase
{
    public function testDefaultsToAllNullAndIsEmpty(): void
    {
        $style = new StyleSettings();

        $this->assertNull($style->getFontColor());
        $this->assertNull($style->getBackgroundColor());
        $this->assertNull($style->getButtonColor());
        $this->assertNull($style->getGlowEnabled());
        $this->assertTrue($style->isEmpty());
    }

    public function testSettersRoundTripAndIsNotEmpty(): void
    {
        $style = new StyleSettings();
        $style->setFontColor('#1F2937');
        $style->setButtonColor('#FF6B35');
        $style->setGlowEnabled(true);

        $this->assertSame('#1F2937', $style->getFontColor());
        $this->assertSame('#FF6B35', $style->getButtonColor());
        $this->assertTrue($style->getGlowEnabled());
        $this->assertFalse($style->isEmpty());
    }
}
