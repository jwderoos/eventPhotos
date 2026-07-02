<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Style;

use App\Service\Style\ResolvedStyle;
use PHPUnit\Framework\TestCase;

final class ResolvedStyleTest extends TestCase
{
    public function testNullFieldsStayNull(): void
    {
        $style = new ResolvedStyle(null, null, null, false);

        $this->assertNull($style->fontColor);
        $this->assertNull($style->backgroundColor);
        $this->assertNull($style->buttonContentColor());
        $this->assertNull($style->backgroundCss());
    }

    public function testButtonContentColorIsWhiteOnDarkButton(): void
    {
        $style = new ResolvedStyle(null, null, '#1F2937', false);
        $this->assertSame('#FFFFFF', $style->buttonContentColor());
    }

    public function testButtonContentColorIsBlackOnLightButton(): void
    {
        $style = new ResolvedStyle(null, null, '#FDE68A', false);
        $this->assertSame('#000000', $style->buttonContentColor());
    }

    public function testBackgroundCssIsFlatWhenGlowOff(): void
    {
        $style = new ResolvedStyle(null, '#EEEEEE', '#FF6B35', false);
        $this->assertSame('#EEEEEE', $style->backgroundCss());
    }

    public function testBackgroundCssIsGradientDerivedFromButtonWhenGlowOn(): void
    {
        $style = new ResolvedStyle(null, '#FFFFFF', '#FF6B35', true);
        // #FF6B35 -> rgb(255, 107, 53)
        $this->assertSame('radial-gradient(circle, rgba(255, 107, 53, 0.4), #FFFFFF)', $style->backgroundCss());
    }

    public function testGlowOnWithoutButtonFallsBackToFlatBackground(): void
    {
        $style = new ResolvedStyle(null, '#FFFFFF', null, true);
        $this->assertSame('#FFFFFF', $style->backgroundCss());
    }

    public function testGlowOnWithoutBackgroundUsesWhiteBase(): void
    {
        $style = new ResolvedStyle(null, null, '#FF6B35', true);
        $this->assertSame('radial-gradient(circle, rgba(255, 107, 53, 0.4), #FFFFFF)', $style->backgroundCss());
    }
}
