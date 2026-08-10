<?php

declare(strict_types=1);

namespace Shykat\WebpBolt\Contracts;

use GdImage;

/**
 * An Encoder writes a GD image to disk in a specific output format.
 * Add a new output format (AVIF, JPEG, ...) by writing a new Encoder.
 */
interface EncoderInterface
{
    /**
     * Save the image to $destinationPath. Returns true on success.
     */
    public function encode(GdImage $image, string $destinationPath): bool;

    /**
     * The file extension this encoder produces, e.g. "webp".
     */
    public function extension(): string;
}
