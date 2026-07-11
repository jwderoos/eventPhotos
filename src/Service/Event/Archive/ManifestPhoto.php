<?php

declare(strict_types=1);

namespace App\Service\Event\Archive;

final readonly class ManifestPhoto
{
    public function __construct(
        public string $contentHash,
        public string $originalFilename,
        public int $byteSize,
        public int $width,
        public int $height,
        public ?string $takenAt,
        public int $derivativeBytes,
        public string $createdAt,
    ) {
    }
}
