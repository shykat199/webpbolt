<?php

declare(strict_types=1);

namespace Shykat\WebpBolt\Contracts;

use GdImage;

/**
 * A Transform modifies an image in place (resize, grayscale, watermark, ...).
 * Any new feature that changes pixels should implement this interface.
 */
interface TransformInterface
{
    /**
     * Apply the transformation and return the resulting image.
     */
    public function apply(GdImage $image): GdImage;
}
