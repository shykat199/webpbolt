<?php

declare(strict_types=1);

namespace Shykat\WebpBolt\Encoders;

use GdImage;
use InvalidArgumentException;
use RuntimeException;
use Shykat\WebpBolt\Contracts\EncoderInterface;

class WebpEncoder implements EncoderInterface
{
    private int $quality;

    public function __construct(int $quality = 80)
    {
        if (!function_exists('imagewebp')) {
            throw new RuntimeException('Your PHP build of GD does not support WebP.');
        }

        if ($quality < 0 || $quality > 100) {
            throw new InvalidArgumentException('Quality must be between 0 and 100.');
        }

        $this->quality = $quality;
    }

    public function encode(GdImage $image, string $destinationPath): bool
    {
        imagepalettetotruecolor($image);
        imagealphablending($image, false);   // was true — must be false to save alpha
        imagesavealpha($image, true);

        return imagewebp($image, $destinationPath, $this->quality);
    }

    public function extension(): string
    {
        return 'webp';
    }
}