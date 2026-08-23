<?php

declare(strict_types=1);

namespace Shykat\WebpBolt\Enums;

/**
 * Where a watermark sits on the image.
 *
 * A backed enum rather than loose strings so your IDE autocompletes the
 * nine valid values and a typo is a compile-time problem instead of a
 * silently mis-placed logo. Position::from('bottom-right') still works if
 * you are reading the value out of a config file.
 */
enum Position: string
{
    case TopLeft      = 'top-left';
    case TopCenter    = 'top-center';
    case TopRight     = 'top-right';
    case MiddleLeft   = 'middle-left';
    case Center       = 'center';
    case MiddleRight  = 'middle-right';
    case BottomLeft   = 'bottom-left';
    case BottomCenter = 'bottom-center';
    case BottomRight  = 'bottom-right';

    /**
     * Work out the top-left pixel where a box of the given size should be
     * drawn, inside a canvas of the given size, respecting the margin.
     *
     * @return array{0: int, 1: int} [x, y]
     */
    public function offset(
        int $canvasWidth,
        int $canvasHeight,
        int $boxWidth,
        int $boxHeight,
        int $margin
    ): array {
        $x = match ($this) {
            self::TopLeft, self::MiddleLeft, self::BottomLeft => $margin,

            self::TopCenter, self::Center, self::BottomCenter
                => (int) round(($canvasWidth - $boxWidth) / 2),

            self::TopRight, self::MiddleRight, self::BottomRight
                => $canvasWidth - $boxWidth - $margin,
        };

        $y = match ($this) {
            self::TopLeft, self::TopCenter, self::TopRight => $margin,

            self::MiddleLeft, self::Center, self::MiddleRight
                => (int) round(($canvasHeight - $boxHeight) / 2),

            self::BottomLeft, self::BottomCenter, self::BottomRight
                => $canvasHeight - $boxHeight - $margin,
        };

        // On an image smaller than the watermark plus margins these can go
        // negative, which would clip off the canvas. Clamp to zero.
        return [max(0, $x), max(0, $y)];
    }
}
