<?php

declare(strict_types=1);

namespace App\Service\Session;

use Symfony\Component\DependencyInjection\Attribute\Autowire;

final readonly class GeoIpFeatureFlag
{
    public function __construct(
        #[Autowire('%env(default::MAXMIND_LICENSE_KEY)%')]
        private ?string $licenseKey = null,
    ) {
    }

    public function isEnabled(): bool
    {
        return $this->licenseKey !== null && trim($this->licenseKey) !== '';
    }

    public function getLicenseKey(): string
    {
        return $this->licenseKey ?? '';
    }
}
