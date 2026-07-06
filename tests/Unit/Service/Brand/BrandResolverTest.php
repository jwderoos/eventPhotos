<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Brand;

use App\Entity\Event;
use App\Entity\OrganizerProfile;
use App\Entity\User;
use App\Repository\OrganizerProfileRepository;
use App\Service\Brand\BrandResolver;
use App\Service\Brand\ResolvedBrand;
use DateTimeImmutable;
use PHPUnit\Framework\TestCase;

final class BrandResolverTest extends TestCase
{
    private function event(User $owner): Event
    {
        return new Event(
            'some-slug',
            'Some Event',
            new DateTimeImmutable('2026-07-15 10:00'),
            new DateTimeImmutable('2026-07-15 14:00'),
            $owner,
        );
    }

    public function testReturnsNullWhenNoProfile(): void
    {
        $owner = new User('owner@example.com', 'Owner');

        $repo = $this->createStub(OrganizerProfileRepository::class);
        $repo->method('findOneBy')->willReturn(null);

        $resolver = new BrandResolver($repo);

        $this->assertNotInstanceOf(ResolvedBrand::class, $resolver->resolve($this->event($owner)));
    }

    public function testReturnsNullWhenProfileHasNoBrand(): void
    {
        $owner = new User('owner@example.com', 'Owner');
        $profile = new OrganizerProfile($owner);

        $repo = $this->createStub(OrganizerProfileRepository::class);
        $repo->method('findOneBy')->willReturn($profile);

        $resolver = new BrandResolver($repo);

        $this->assertNotInstanceOf(ResolvedBrand::class, $resolver->resolve($this->event($owner)));
    }

    public function testResolvesLabelLogoAndUrl(): void
    {
        $owner = new User('owner@example.com', 'Owner');
        $profile = new OrganizerProfile($owner);
        $profile->setBrandLabel('Acme Corp');
        $profile->setBrandLogoFilename('acme-abc123.png');
        $profile->setBrandUrl('https://acme.example');

        $repo = $this->createStub(OrganizerProfileRepository::class);
        $repo->method('findOneBy')->willReturn($profile);

        $resolved = new BrandResolver($repo)->resolve($this->event($owner));

        $this->assertInstanceOf(ResolvedBrand::class, $resolved);
        $this->assertSame('Acme Corp', $resolved->label);
        $this->assertTrue($resolved->hasLogo);
        $this->assertSame('https://acme.example', $resolved->url);
    }

    public function testResolvesLabelOnlyWithoutLogoOrUrl(): void
    {
        $owner = new User('owner@example.com', 'Owner');
        $profile = new OrganizerProfile($owner);
        $profile->setBrandLabel('Acme Corp');

        $repo = $this->createStub(OrganizerProfileRepository::class);
        $repo->method('findOneBy')->willReturn($profile);

        $resolved = new BrandResolver($repo)->resolve($this->event($owner));

        $this->assertInstanceOf(ResolvedBrand::class, $resolved);
        $this->assertSame('Acme Corp', $resolved->label);
        $this->assertFalse($resolved->hasLogo);
        $this->assertNull($resolved->url);
    }
}
