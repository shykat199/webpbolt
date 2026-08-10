<?php

declare(strict_types=1);

namespace Shykat\WebpBolt;

use GdImage;
use InvalidArgumentException;
use RuntimeException;

/**
 * Loads a supported image file from disk into a GD image resource.
 */
class ImageLoader
{
    public function load(string $path): GdImage
    {
        if (!is_file($path)) {
            throw new InvalidArgumentException("File not found: {$path}");
        }

        $info = getimagesize($path);
        if ($info === false) {
            throw new RuntimeException('The file is not a valid image.');
        }

        // To support a new input format, add one line to this match.
        $image = match ($info[2]) {
            IMAGETYPE_JPEG => imagecreatefromjpeg($path),
            IMAGETYPE_PNG  => imagecreatefrompng($path),
            IMAGETYPE_GIF  => imagecreatefromgif($path),
            IMAGETYPE_BMP  => imagecreatefrombmp($path),
            default => throw new InvalidArgumentException(
                'Unsupported input format. Supported: JPG, PNG, GIF, BMP.'
            ),
        };

        if ($image === false) {
            throw new RuntimeException('Could not read the image data.');
        }

        return $image;
    }
}
