<?php

declare(strict_types=1);

namespace Shykat\WebpBolt\Transforms;

use GdImage;
use Shykat\WebpBolt\Contracts\TransformInterface;

/**
 * Resize an image to a maximum width/height, keeping aspect ratio.
 */
class Resize implements TransformInterface
{
    public function __construct(
        private int $maxWidth,
        private int $maxHeight
    ) {
    }

    public function apply(GdImage $image): GdImage
    {
        $width  = imagesx($image);
        $height = imagesy($image);

        // Scale factor that fits the image inside the max box.
        $ratio = min($this->maxWidth / $width, $this->maxHeight / $height, 1);

        $newWidth  = (int) round($width * $ratio);
        $newHeight = (int) round($height * $ratio);

        if ($newWidth === $width && $newHeight === $height) {
            return $image; // already small enough
        }

        $resized = imagecreatetruecolor($newWidth, $newHeight);

        // Keep transparency.
        imagealphablending($resized, false);
        imagesavealpha($resized, true);

        imagecopyresampled(
            $resized, $image,
            0, 0, 0, 0,
            $newWidth, $newHeight, $width, $height
        );

        imagedestroy($image);

        return $resized;
    }
}
