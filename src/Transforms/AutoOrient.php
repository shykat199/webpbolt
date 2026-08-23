<?php

declare(strict_types=1);

namespace Shykat\WebpBolt\Transforms;

use GdImage;
use Shykat\WebpBolt\Contracts\TransformInterface;

/**
 * Rotate the image to match its EXIF Orientation flag.
 *
 * WHY THIS EXISTS
 * A phone does not rotate the pixels when you turn it sideways. It writes
 * the image in sensor order and records how it should be displayed in an
 * EXIF tag. GD ignores that tag completely, so without this transform a
 * large share of real user uploads come out rotated.
 *
 * WHY IT TAKES A PATH
 * TransformInterface::apply() only receives a GdImage, and EXIF lives in
 * the file, not in the decoded bitmap. So the path is passed to the
 * constructor instead. Widening the interface would be cleaner but would
 * break every existing transform, so this stays as it is.
 *
 * Only JPEG carries EXIF. For anything else this is a no-op and costs one
 * failed read.
 */
class AutoOrient implements TransformInterface
{
    public function __construct(private string $sourcePath)
    {
    }

    public function apply(GdImage $image): GdImage
    {
        if (!function_exists('exif_read_data')) {
            return $image;
        }

        // Suppressed: a PNG or a JPEG with no EXIF block raises a warning
        // rather than returning cleanly, and neither is an error here.
        $exif = @exif_read_data($this->sourcePath);

        if ($exif === false || !isset($exif['Orientation'])) {
            return $image;
        }

        $orientation = (int) $exif['Orientation'];

        // imagerotate turns counter-clockwise, so the angles are inverted
        // relative to how the EXIF spec describes the rotation.
        //
        // 1 = already upright, 2/4/5/7 are mirrored variants that virtually
        // never occur from a camera, so they are left alone.
        $angle = match ($orientation) {
            3 => 180,
            6 => -90,
            8 => 90,
            default => 0,
        };

        if ($angle === 0) {
            return $image;
        }

        $rotated = imagerotate($image, $angle, 0);

        if ($rotated === false) {
            return $image;
        }

        // Rotation builds a new canvas, so the old handle must go. Same
        // contract as Resize: treat the handle you passed in as invalid.
        imagealphablending($rotated, false);
        imagesavealpha($rotated, true);
        imagedestroy($image);

        return $rotated;
    }
}
