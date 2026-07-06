<?php

declare(strict_types=1);

namespace App\Service\Brand;

use App\Entity\Event;
use App\Entity\User;
use App\Repository\OrganizerProfileRepository;

final readonly class BrandResolver
{
    public function __construct(
        private OrganizerProfileRepository $profiles,
    ) {
    }

    public function resolveForOwner(User $owner): ?ResolvedBrand
    {
        $profile = $this->profiles->findOneBy(['user' => $owner]);

        if ($profile === null || !$profile->hasBrand()) {
            return null;
        }

        return new ResolvedBrand(
            label:   $profile->getBrandLabel(),
            hasLogo: $profile->getBrandLogoFilename() !== null,
            url:     $profile->getBrandUrl(),
        );
    }

    public function resolve(Event $event): ?ResolvedBrand
    {
        return $this->resolveForOwner($event->getOwner());
    }
}
