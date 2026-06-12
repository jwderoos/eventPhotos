<?php

declare(strict_types=1);

namespace App\Twig;

use Override;
use App\Service\Auth\GoogleOAuthFeatureFlag;
use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

final class GoogleOAuthExtension extends AbstractExtension
{
    public function __construct(
        private readonly GoogleOAuthFeatureFlag $flag,
    ) {
    }

    /** @return list<TwigFunction> */
    #[Override]
    public function getFunctions(): array
    {
        return [
            new TwigFunction('google_oauth_enabled', $this->isEnabled(...)),
        ];
    }

    public function isEnabled(): bool
    {
        return $this->flag->isEnabled();
    }
}
