<?php

declare(strict_types=1);

namespace App\Service\Auth;

use Symfony\Bundle\FrameworkBundle\Routing\Attribute\AsRoutingConditionService;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

#[AsRoutingConditionService(alias: GoogleOAuthFeatureFlag::class)]
final readonly class GoogleOAuthFeatureFlag
{
    public function __construct(
        #[Autowire('%env(default::GOOGLE_OAUTH_CLIENT_ID)%')]
        private ?string $clientId = null,
    ) {
    }

    public function isEnabled(): bool
    {
        return $this->clientId !== null && trim($this->clientId) !== '';
    }
}
