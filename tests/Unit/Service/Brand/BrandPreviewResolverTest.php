<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Brand;

use ReflectionProperty;
use App\Entity\OrganizerProfile;
use App\Entity\User;
use App\Repository\OrganizerProfileRepository;
use App\Service\Brand\BrandPreview;
use App\Service\Brand\BrandPreviewResolver;
use App\Service\Brand\BrandResolver;
use PHPUnit\Framework\TestCase;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

final class BrandPreviewResolverTest extends TestCase
{
    private function ownerWithId(string $email, int $id): User
    {
        $owner = new User($email, 'Owner');
        $ref = new ReflectionProperty(User::class, 'id');
        $ref->setValue($owner, $id);

        return $owner;
    }

    private function resolverFor(
        ?OrganizerProfile $profile,
        ?User $currentUser,
        UrlGeneratorInterface $urls,
    ): BrandPreviewResolver {
        $repo = $this->createStub(OrganizerProfileRepository::class);
        $repo->method('findOneBy')->willReturn($profile);

        $security = $this->createStub(Security::class);
        $security->method('getUser')->willReturn($currentUser);

        return new BrandPreviewResolver(new BrandResolver($repo), $urls, $security);
    }

    public function testReturnsNullWhenOwnerHasNoBrand(): void
    {
        $owner = $this->ownerWithId('owner@example.com', 1);
        $urls = $this->createStub(UrlGeneratorInterface::class);

        $this->assertNotInstanceOf(BrandPreview::class, $this->resolverFor(null, $owner, $urls)->forOwner($owner));
    }

    public function testSelfOwnerWithLogoUsesAccountRoute(): void
    {
        $owner = $this->ownerWithId('owner@example.com', 7);
        $profile = new OrganizerProfile($owner);
        $profile->setBrandLabel('Acme');
        $profile->setBrandLogoFilename('acme.png');

        $urls = $this->createMock(UrlGeneratorInterface::class);
        $urls->expects($this->once())
            ->method('generate')
            ->with('account_brand_logo')
            ->willReturn('/account/brand-logo');

        $preview = $this->resolverFor($profile, $owner, $urls)->forOwner($owner);

        $this->assertInstanceOf(BrandPreview::class, $preview);
        $this->assertSame('Acme', $preview->label);
        $this->assertSame('/account/brand-logo', $preview->logoUrl);
    }

    public function testAdminEditingAnotherOwnerUsesAdminUserRoute(): void
    {
        $owner = $this->ownerWithId('owner@example.com', 42);
        $admin = $this->ownerWithId('admin@example.com', 1);
        $profile = new OrganizerProfile($owner);
        $profile->setBrandLabel('Acme');
        $profile->setBrandLogoFilename('acme.png');

        $urls = $this->createMock(UrlGeneratorInterface::class);
        $urls->expects($this->once())
            ->method('generate')
            ->with('admin_user_brand_logo', ['id' => 42])
            ->willReturn('/admin/users/42/brand-logo');

        $preview = $this->resolverFor($profile, $admin, $urls)->forOwner($owner);

        $this->assertInstanceOf(BrandPreview::class, $preview);
        $this->assertSame('/admin/users/42/brand-logo', $preview->logoUrl);
    }

    public function testLabelOnlyBrandHasNullLogoUrl(): void
    {
        $owner = $this->ownerWithId('owner@example.com', 5);
        $profile = new OrganizerProfile($owner);
        $profile->setBrandLabel('Acme');

        $urls = $this->createMock(UrlGeneratorInterface::class);
        $urls->expects($this->never())->method('generate');

        $preview = $this->resolverFor($profile, $owner, $urls)->forOwner($owner);

        $this->assertInstanceOf(BrandPreview::class, $preview);
        $this->assertSame('Acme', $preview->label);
        $this->assertNull($preview->logoUrl);
    }
}
