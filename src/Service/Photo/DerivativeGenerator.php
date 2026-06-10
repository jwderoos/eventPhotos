<?php

declare(strict_types=1);

namespace App\Service\Photo;

use GdImage;
use League\Flysystem\FilesystemOperator;
use RuntimeException;
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
    ) {
    }

    /**
     * @return array{0:int,1:int} [width, height] of the original image
     */
    public function generate(string $path): array
    {
        $bytes = $this->originals->read($path);
        $image = @imagecreatefromstring($bytes);

        if ($image === false) {
            throw new RuntimeException(sprintf('Could not decode JPEG at "%s".', $path));
        }

        $width  = imagesx($image);
        $height = imagesy($image);

        $this->thumbs->write(
            $path,
            $this->encode($this->scaleTo($image, $width, $height, self::THUMB_LONG_EDGE), self::THUMB_QUALITY),
        );
        $this->previews->write(
            $path,
            $this->encode($this->scaleTo($image, $width, $height, self::PREVIEW_LONG_EDGE), self::PREVIEW_QUALITY),
        );

        return [$width, $height];
    }

    /**
     * @param int<1,max> $srcW
     * @param int<1,max> $srcH
     * @param int<1,max> $longEdge
     */
    private function scaleTo(GdImage $source, int $srcW, int $srcH, int $longEdge): GdImage
    {
        $longest = max($srcW, $srcH);
        if ($longest <= $longEdge) {
            // Source is already smaller than the target — re-encode a copy at native size.
            $copy = imagecreatetruecolor($srcW, $srcH);
            imagecopy($copy, $source, 0, 0, 0, 0, $srcW, $srcH);
            return $copy;
        }

        $ratio = $longEdge / $longest;
        $dstW = (int) round($srcW * $ratio);
        $dstH = (int) round($srcH * $ratio);

        $scaled = @imagescale($source, $dstW, $dstH, IMG_BICUBIC);
        if ($scaled === false) {
            // Fallback to default mode (some PHP builds reject IMG_BICUBIC).
            $scaled = imagescale($source, $dstW, $dstH);
        }

        if ($scaled === false) {
            throw new RuntimeException('imagescale failed.');
        }

        return $scaled;
    }

    private function encode(GdImage $image, int $quality): string
    {
        ob_start();
        imagejpeg($image, null, $quality);
        $bytes = ob_get_clean();

        if (!is_string($bytes) || $bytes === '') {
            throw new RuntimeException('imagejpeg produced no output.');
        }

        return $bytes;
    }
}
