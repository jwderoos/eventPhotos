<?php

declare(strict_types=1);

namespace App\Service\Image;

use GdImage;
use RuntimeException;

final class GdImageResizer
{
    public function decode(string $bytes): GdImage
    {
        $image = @imagecreatefromstring($bytes);

        if ($image === false) {
            throw new RuntimeException('Could not decode image bytes.');
        }

        return $image;
    }

    /**
     * @param int<1,max> $srcW
     * @param int<1,max> $srcH
     * @param int<1,max> $longEdge
     */
    public function scaleTo(GdImage $source, int $srcW, int $srcH, int $longEdge): GdImage
    {
        $longest = max($srcW, $srcH);
        if ($longest <= $longEdge) {
            // Source is already smaller than the target — re-encode a copy at native size.
            $copy = imagecreatetruecolor($srcW, $srcH);
            imagecopy($copy, $source, 0, 0, 0, 0, $srcW, $srcH);

            return $copy;
        }

        $ratio = $longEdge / $longest;
        $dstW  = (int) round($srcW * $ratio);
        $dstH  = (int) round($srcH * $ratio);

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

    public function encode(GdImage $image, int $quality): string
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
