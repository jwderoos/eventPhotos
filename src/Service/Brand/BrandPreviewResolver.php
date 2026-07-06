<?php

declare(strict_types=1);

namespace App\Service\Brand;

use App\Entity\User;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

final readonly class BrandPreviewResolver
{
    public function __construct(
        private BrandResolver $brands,
        private UrlGeneratorInterface $urls,
        private Security $security,
    ) {
    }

    public function forOwner(User $owner): ?BrandPreview
    {
        $brand = $this->brands->resolveForOwner($owner);

        if (!$brand instanceof ResolvedBrand) {
            return null;
        }

        $logoUrl = null;
        if ($brand->hasLogo) {
            $current = $this->security->getUser();
            $logoUrl = $current instanceof User && $current->getId() === $owner->getId()
                ? $this->urls->generate('account_brand_logo')
                : $this->urls->generate('admin_user_brand_logo', ['id' => $owner->getId()]);
        }

        return new BrandPreview(label: $brand->label, logoUrl: $logoUrl);
    }
}
