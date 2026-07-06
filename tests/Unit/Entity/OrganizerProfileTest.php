<?php

declare(strict_types=1);

namespace App\Tests\Unit\Entity;

use App\Entity\OrganizerProfile;
use App\Entity\User;
use PHPUnit\Framework\TestCase;

final class OrganizerProfileTest extends TestCase
{
    private function profile(): OrganizerProfile
    {
        return new OrganizerProfile(new User('owner@example.com', 'Owner'));
    }

    public function testHasBrandIsFalseWhenNeitherLabelNorLogoSet(): void
    {
        $this->assertFalse($this->profile()->hasBrand());
    }

    public function testHasBrandIsTrueWithLabelOnly(): void
    {
        $profile = $this->profile();
        $profile->setBrandLabel('Acme Corp');

        $this->assertTrue($profile->hasBrand());
    }

    public function testHasBrandIsTrueWithLogoOnly(): void
    {
        $profile = $this->profile();
        $profile->setBrandLogoFilename('acme-abc123.png');

        $this->assertTrue($profile->hasBrand());
    }

    public function testHasBrandIsTrueWithBoth(): void
    {
        $profile = $this->profile();
        $profile->setBrandLabel('Acme Corp');
        $profile->setBrandLogoFilename('acme-abc123.png');

        $this->assertTrue($profile->hasBrand());
    }

    public function testBrandUrlRoundTrips(): void
    {
        $profile = $this->profile();
        $profile->setBrandUrl('https://acme.example');

        $this->assertSame('https://acme.example', $profile->getBrandUrl());
    }

    public function testEmptyStringLabelAndUrlNormalizeToNullAndDoNotCountAsBrand(): void
    {
        $profile = $this->profile();
        $profile->setBrandLabel('');
        $profile->setBrandUrl('');

        $this->assertNull($profile->getBrandLabel());
        $this->assertNull($profile->getBrandUrl());
        $this->assertFalse($profile->hasBrand());
    }
}
