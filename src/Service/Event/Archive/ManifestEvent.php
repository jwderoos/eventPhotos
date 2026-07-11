<?php

declare(strict_types=1);

namespace App\Service\Event\Archive;

final readonly class ManifestEvent
{
    public function __construct(
        public string $name,
        public string $slug,
        public ?string $description,
        public string $timezone,
        public string $startsAt,
        public string $endsAt,
        public ?string $publishedAt,
        public bool $notificationsEnabled,
        public ?string $fontColor,
        public ?string $backgroundColor,
        public ?string $buttonColor,
        public ?bool $glowEnabled,
        public ?string $logoFilename,
    ) {
    }
}
