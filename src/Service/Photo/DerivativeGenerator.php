<?php

declare(strict_types=1);

namespace App\Service\Photo;

use App\Service\Image\GdImageResizer;
use League\Flysystem\FilesystemOperator;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

final readonly class DerivativeGenerator
{
    private const int THUMB_LONG_EDGE   = 400;

    private const int THUMB_QUALITY     = 80;

    private const int PREVIEW_LONG_EDGE = 1600;

    private const int PREVIEW_QUALITY   = 85;

    public function __construct(
        #[Autowire(service: 'photo_originals_storage')]
        private FilesystemOperator $originals,
        #[Autowire(service: 'photo_thumbs_storage')]
        private FilesystemOperator $thumbs,
        #[Autowire(service: 'photo_previews_storage')]
        private FilesystemOperator $previews,
        private GdImageResizer $resizer,
    ) {
    }

    /**
     * @return array{0:int,1:int,2:int} [width, height, derivativeBytes]
     *                                   — derivativeBytes is the sum of thumb + preview JPEG payload sizes.
     */
    public function generate(string $path): array
    {
        $image = $this->resizer->decode($this->originals->read($path));

        $width  = imagesx($image);
        $height = imagesy($image);

        $thumbBytes   = $this->resizer->encode(
            $this->resizer->scaleTo($image, $width, $height, self::THUMB_LONG_EDGE),
            self::THUMB_QUALITY,
        );
        $previewBytes = $this->resizer->encode(
            $this->resizer->scaleTo($image, $width, $height, self::PREVIEW_LONG_EDGE),
            self::PREVIEW_QUALITY,
        );

        $this->thumbs->write($path, $thumbBytes);
        $this->previews->write($path, $previewBytes);

        return [$width, $height, strlen($thumbBytes) + strlen($previewBytes)];
    }
}
