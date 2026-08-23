<?php

declare(strict_types=1);

namespace Shykat\WebpBolt\Transforms;

use GdImage;
use Shykat\WebpBolt\Contracts\TransformInterface;
use Shykat\WebpBolt\Enums\Position;
use Shykat\WebpBolt\Exceptions\ImageNotFoundException;
use Shykat\WebpBolt\Exceptions\UnsupportedFormatException;
use Shykat\WebpBolt\Exceptions\WebpBoltException;

/**
 * Stamp text or a logo onto an image.
 *
 * HOW YOU USE IT
 * Two named constructors, then chain only what you want to change:
 *
 *   Watermark::text('(c) 2026 Studio')
 *   Watermark::image('logo.png')->position(Position::TopRight)->scale(0.2)
 *
 * Every setter has a sensible default, so the one-liner above is a complete,
 * working watermark. You never have to configure something you do not care
 * about.
 *
 * WHY IT IS ONE CLASS AND NOT TWO
 * Position, opacity and margin behave identically whether you are stamping
 * a logo or a line of text. Splitting into TextWatermark and ImageWatermark
 * would duplicate all of that. The two modes only differ in how the stamp
 * is produced, which is one private method each.
 *
 * ORDER MATTERS
 * Add this AFTER Resize. Watermarking first means the logo gets shrunk with
 * the photo and comes out tiny and blurry.
 */
class Watermark implements TransformInterface
{
    private const MODE_TEXT  = 'text';
    private const MODE_IMAGE = 'image';

    private Position $position = Position::BottomRight;

    private int $opacity = 60;

    private int $margin = 20;

    /** Watermark width as a fraction of the base image width. */
    private float $scale = 0.18;

    /** Path to a .ttf file, or null to use GD's built-in bitmap font. */
    private ?string $fontFile = null;

    private int $fontSize = 20;

    /** @var array{0:int,1:int,2:int} RGB */
    private array $color = [255, 255, 255];

    private function __construct(
        private string $mode,
        private string $value
    ) {
    }

    /**
     * Stamp a line of text.
     *
     * Without a TTF font file this falls back to GD's built-in bitmap font,
     * which is small and blocky but needs no assets. For anything
     * user-facing, call font() with a real .ttf.
     */
    public static function text(string $text): self
    {
        return new self(self::MODE_TEXT, $text);
    }

    /**
     * Stamp an image file. PNG with transparency is what you want here.
     */
    public static function image(string $path): self
    {
        return new self(self::MODE_IMAGE, $path);
    }

    /** Where to place it. Accepts the enum or its string value. */
    public function position(Position|string $position): self
    {
        $this->position = $position instanceof Position
            ? $position
            : Position::from($position);

        return $this;
    }

    /** 0 = invisible, 100 = fully opaque. Default 60. */
    public function opacity(int $percent): self
    {
        if ($percent < 0 || $percent > 100) {
            throw new WebpBoltException('Watermark opacity must be between 0 and 100.');
        }

        $this->opacity = $percent;

        return $this;
    }

    /** Gap from the image edge, in pixels. Ignored for centred positions. */
    public function margin(int $pixels): self
    {
        $this->margin = max(0, $pixels);

        return $this;
    }

    /**
     * Image mode only. Watermark width as a fraction of the base image
     * width, so the logo stays proportionate on every photo instead of
     * being huge on thumbnails and invisible on full-size shots.
     *
     * 0.18 means "the logo is 18% as wide as the photo".
     */
    public function scale(float $fraction): self
    {
        if ($fraction <= 0 || $fraction > 1) {
            throw new WebpBoltException('Watermark scale must be between 0 and 1.');
        }

        $this->scale = $fraction;

        return $this;
    }

    /** Text mode only. Path to a .ttf file, plus point size. */
    public function font(string $ttfPath, int $size = 20): self
    {
        if (!is_file($ttfPath)) {
            throw new ImageNotFoundException("Font file not found: {$ttfPath}");
        }

        $this->fontFile = $ttfPath;
        $this->fontSize = $size;

        return $this;
    }

    /** Text mode only. Accepts '#ffffff', 'ffffff' or [255, 255, 255]. */
    public function color(string|array $color): self
    {
        if (is_array($color)) {
            if (count($color) !== 3) {
                throw new WebpBoltException('Colour array must hold exactly R, G and B.');
            }

            $this->color = [(int) $color[0], (int) $color[1], (int) $color[2]];

            return $this;
        }

        $hex = ltrim($color, '#');

        if (strlen($hex) === 3) {
            $hex = $hex[0] . $hex[0] . $hex[1] . $hex[1] . $hex[2] . $hex[2];
        }

        if (!preg_match('/^[0-9a-fA-F]{6}$/', $hex)) {
            throw new WebpBoltException("Not a valid hex colour: {$color}");
        }

        $this->color = [
            (int) hexdec(substr($hex, 0, 2)),
            (int) hexdec(substr($hex, 2, 2)),
            (int) hexdec(substr($hex, 4, 2)),
        ];

        return $this;
    }

    public function apply(GdImage $image): GdImage
    {
        // A previous transform may have left blending off (Resize does, to
        // preserve alpha). Compositing needs it on, or the watermark would
        // punch a transparent hole instead of drawing over the pixels.
        imagealphablending($image, true);

        $this->mode === self::MODE_TEXT
            ? $this->drawText($image)
            : $this->drawImage($image);

        return $image;
    }

    /**
     * GD's alpha channel runs 0 (opaque) to 127 (invisible) — backwards
     * from how everyone thinks about opacity, hence this one-liner sitting
     * in its own method rather than being inlined twice.
     */
    private function alphaChannel(): int
    {
        return (int) round(127 - ($this->opacity / 100 * 127));
    }

    private function drawText(GdImage $image): void
    {
        $alpha = $this->alphaChannel();
        $color = imagecolorallocatealpha(
            $image,
            $this->color[0],
            $this->color[1],
            $this->color[2],
            $alpha
        );

        if ($color === false) {
            throw new WebpBoltException('Could not allocate the watermark colour.');
        }

        $canvasWidth  = imagesx($image);
        $canvasHeight = imagesy($image);

        if ($this->fontFile !== null && function_exists('imagettftext')) {
            $box = imagettfbbox($this->fontSize, 0, $this->fontFile, $this->value);

            if ($box === false) {
                throw new UnsupportedFormatException('Could not measure the watermark text.');
            }

            // imagettfbbox returns eight corner coordinates, not a width and
            // height, so the extents have to be derived from them.
            $textWidth  = (int) (max($box[0], $box[2], $box[4], $box[6]) - min($box[0], $box[2], $box[4], $box[6]));
            $textHeight = (int) (max($box[1], $box[3], $box[5], $box[7]) - min($box[1], $box[3], $box[5], $box[7]));

            [$x, $y] = $this->position->offset(
                $canvasWidth, $canvasHeight, $textWidth, $textHeight, $this->margin
            );

            // imagettftext places text by its BASELINE, not its top edge,
            // so the height has to be added back or the text sits one line
            // too high and clips off the top.
            imagettftext($image, $this->fontSize, 0, $x, $y + $textHeight, $color, $this->fontFile, $this->value);

            return;
        }

        // Fallback: GD's built-in bitmap fonts, sizes 1-5 only.
        $font       = 5;
        $textWidth  = imagefontwidth($font) * strlen($this->value);
        $textHeight = imagefontheight($font);

        [$x, $y] = $this->position->offset(
            $canvasWidth, $canvasHeight, $textWidth, $textHeight, $this->margin
        );

        imagestring($image, $font, $x, $y, $this->value, $color);
    }

    private function drawImage(GdImage $image): void
    {
        if (!is_file($this->value)) {
            throw new ImageNotFoundException("Watermark image not found: {$this->value}");
        }

        $info = @getimagesize($this->value);

        if ($info === false) {
            throw new UnsupportedFormatException('The watermark file is not a readable image.');
        }

        $stamp = match ($info[2]) {
            IMAGETYPE_PNG  => imagecreatefrompng($this->value),
            IMAGETYPE_JPEG => imagecreatefromjpeg($this->value),
            IMAGETYPE_GIF  => imagecreatefromgif($this->value),
            IMAGETYPE_WEBP => imagecreatefromwebp($this->value),
            default => throw new UnsupportedFormatException(
                'Watermark must be a PNG, JPG, GIF or WebP.'
            ),
        };

        if ($stamp === false) {
            throw new UnsupportedFormatException('Could not read the watermark image.');
        }

        $canvasWidth  = imagesx($image);
        $canvasHeight = imagesy($image);

        $targetWidth  = max(1, (int) round($canvasWidth * $this->scale));
        $ratio        = $targetWidth / imagesx($stamp);
        $targetHeight = max(1, (int) round(imagesy($stamp) * $ratio));

        $scaled = imagecreatetruecolor($targetWidth, $targetHeight);
        imagealphablending($scaled, false);
        imagesavealpha($scaled, true);
        imagefill($scaled, 0, 0, imagecolorallocatealpha($scaled, 0, 0, 0, 127));

        imagecopyresampled(
            $scaled, $stamp,
            0, 0, 0, 0,
            $targetWidth, $targetHeight,
            imagesx($stamp), imagesy($stamp)
        );

        imagedestroy($stamp);

        // Reducing opacity on an image that already has its own alpha is
        // the awkward part. imagecopymerge flattens transparency, so the
        // trick is COLORIZE with a fifth alpha argument: it ADDS to each
        // pixel's existing alpha, which dims the logo while leaving its
        // transparent background transparent.
        if ($this->opacity < 100) {
            imagefilter($scaled, IMG_FILTER_COLORIZE, 0, 0, 0, $this->alphaChannel());
        }

        [$x, $y] = $this->position->offset(
            $canvasWidth, $canvasHeight, $targetWidth, $targetHeight, $this->margin
        );

        // imagecopy, not imagecopymerge — merge would ignore the per-pixel
        // alpha we just carefully set up.
        imagecopy($image, $scaled, $x, $y, 0, 0, $targetWidth, $targetHeight);

        imagedestroy($scaled);
    }
}
