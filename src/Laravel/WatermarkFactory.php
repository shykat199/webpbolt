<?php

declare(strict_types=1);

namespace Shykat\WebpBolt\Laravel;

use Shykat\WebpBolt\Transforms\Watermark;

/**
 * Turns the `webpbolt.watermark` config array into a Watermark instance.
 *
 * Lives here rather than in the Watermark class itself because the package
 * core must not know that Laravel config exists.
 */
class WatermarkFactory
{
    /**
     * @param array<string, mixed> $config
     */
    public static function fromConfig(array $config): ?Watermark
    {
        $image = $config['image'] ?? null;
        $text  = $config['text'] ?? null;

        if (!$image && !$text) {
            return null;
        }

        $watermark = $image
            ? Watermark::image($image)->scale((float) ($config['scale'] ?? 0.18))
            : Watermark::text($text);

        $watermark
            ->position($config['position'] ?? 'bottom-right')
            ->opacity((int) ($config['opacity'] ?? 60))
            ->margin((int) ($config['margin'] ?? 20));

        if (!$image) {
            $watermark->color($config['color'] ?? '#ffffff');

            if (!empty($config['font'])) {
                $watermark->font($config['font'], (int) ($config['size'] ?? 20));
            }
        }

        return $watermark;
    }
}
