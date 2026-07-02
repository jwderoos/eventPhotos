<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Style;

use App\Entity\StyleSettings;
use App\Repository\OrganizerProfileRepository;
use App\Service\Style\StyleResolver;
use PHPUnit\Framework\TestCase;

final class StyleResolverTest extends TestCase
{
    private function resolver(): StyleResolver
    {
        return new StyleResolver($this->createStub(OrganizerProfileRepository::class));
    }

    private function style(?string $font, ?string $bg, ?string $btn, ?bool $glow): StyleSettings
    {
        $s = new StyleSettings();
        $s->setFontColor($font);
        $s->setBackgroundColor($bg);
        $s->setButtonColor($btn);
        $s->setGlowEnabled($glow);

        return $s;
    }

    public function testEmptyChainResolvesToAllNullAndGlowFalse(): void
    {
        $resolved = $this->resolver()->resolveChain(null, null, null);

        $this->assertNull($resolved->fontColor);
        $this->assertNull($resolved->buttonColor);
        $this->assertFalse($resolved->glowEnabled);
    }

    public function testMostSpecificTierWinsPerField(): void
    {
        $event      = $this->style('#111111', null, null, null);
        $collection = $this->style('#222222', '#333333', null, null);
        $organizer  = $this->style('#999999', '#888888', '#777777', true);

        $resolved = $this->resolver()->resolveChain($event, $collection, $organizer);

        $this->assertSame('#111111', $resolved->fontColor);       // from event
        $this->assertSame('#333333', $resolved->backgroundColor); // event null -> collection
        $this->assertSame('#777777', $resolved->buttonColor);     // only organizer set
        $this->assertTrue($resolved->glowEnabled);                // only organizer set
    }

    public function testFalseGlowAtSpecificTierWinsOverTrueAtParent(): void
    {
        $event     = $this->style(null, null, null, false);
        $organizer = $this->style(null, null, null, true);

        $resolved = $this->resolver()->resolveChain($event, null, $organizer);

        $this->assertFalse($resolved->glowEnabled); // explicit false beats inherited true
    }
}
