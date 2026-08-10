<?php

declare(strict_types=1);

namespace Shykat\WebpBolt\Transforms;

use GdImage;
use Shykat\WebpBolt\Contracts\TransformInterface;

/**
 * Convert an image to grayscale (black & white).
 */
class Grayscale implements TransformInterface
{
    public function apply(GdImage $image): GdImage
    {
        imagefilter($image, IMG_FILTER_GRAYSCALE);

        return $image;
    }
}
