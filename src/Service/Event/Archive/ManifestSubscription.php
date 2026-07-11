<?php

declare(strict_types=1);

namespace App\Service\Event\Archive;

final readonly class ManifestSubscription
{
    public function __construct(
        public string $email,
        public string $status,
        public ?string $confirmedAt,
        public ?string $unsubscribedAt,
        public ?string $notifiedAt,
        public string $createdAt,
    ) {
    }
}
