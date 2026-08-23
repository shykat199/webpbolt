<?php

declare(strict_types=1);

namespace Shykat\WebpBolt;

use Shykat\WebpBolt\Contracts\EncoderInterface;
use Shykat\WebpBolt\Contracts\TransformInterface;
use Shykat\WebpBolt\Enums\Position;
use Shykat\WebpBolt\Encoders\WebpEncoder;
use Shykat\WebpBolt\Transforms\AutoOrient;
use Shykat\WebpBolt\Transforms\Grayscale;
use Shykat\WebpBolt\Transforms\Resize;
use Shykat\WebpBolt\Transforms\Watermark;

/**
 * The friendly front door.
 *
 * ImageProcessor is the engine and stays exactly as it is. This class is a
 * thin fluent wrapper over it so the common case reads as one line with one
 * import:
 *
 *   WebpBolt::make('photo.jpg')->resize(1600)->save();
 *
 * Everything here delegates. Nothing new happens in this file.
 */
class WebpBolt
{
    private ImageProcessor $processor;

    private int $quality = 80;

    private ?EncoderInterface $encoder = null;

    private function __construct(private string $source)
    {
        $this->processor = new ImageProcessor();
    }

    /** Start a conversion from a source file path. */
    public static function make(string $source): self
    {
        return new self($source);
    }

    /**
     * Fit the image inside a box, keeping aspect ratio. Never upscales.
     *
     * Passing one number gives you a square box, which is what you want
     * almost every time: resize(1600) means "no side longer than 1600px".
     */
    public function resize(int $width, ?int $height = null): self
    {
        $this->processor->addTransform(new Resize($width, $height ?? $width));

        return $this;
    }

    /** Convert to black and white. */
    public function grayscale(): self
    {
        $this->processor->addTransform(new Grayscale());

        return $this;
    }

    /**
     * Respect the EXIF orientation flag so phone photos are not sideways.
     *
     * Add this first, before resize, so the image is upright before it is
     * measured.
     */
    public function autoOrient(): self
    {
        $this->processor->addTransform(new AutoOrient($this->source));

        return $this;
    }

    /**
     * Stamp a line of text onto the image.
     *
     * For full control (font, colour, opacity, margin) build a Watermark
     * yourself and pass it to transform() instead.
     */
    public function watermarkText(
        string $text,
        Position|string $position = Position::BottomRight
    ): self {
        $this->processor->addTransform(
            Watermark::text($text)->position($position)
        );

        return $this;
    }

    /** Stamp a logo onto the image. PNG with transparency works best. */
    public function watermarkImage(
        string $path,
        Position|string $position = Position::BottomRight
    ): self {
        $this->processor->addTransform(
            Watermark::image($path)->position($position)
        );

        return $this;
    }

    /** Attach any custom transform implementing TransformInterface. */
    public function transform(TransformInterface $transform): self
    {
        $this->processor->addTransform($transform);

        return $this;
    }

    /** Output quality, 0-100. Ignored if you pass your own encoder. */
    public function quality(int $quality): self
    {
        $this->quality = $quality;

        return $this;
    }

    /** Use a different output format, e.g. an AvifEncoder. */
    public function encoder(EncoderInterface $encoder): self
    {
        $this->encoder = $encoder;

        return $this;
    }

    /**
     * Run the pipeline and write the file.
     *
     * Pass no destination to write beside the original with the encoder's
     * extension swapped in.
     *
     * @return string Path of the file that was written.
     */
    public function save(?string $destination = null): string
    {
        $encoder = $this->encoder ?? new WebpEncoder($this->quality);

        if ($destination !== null) {
            return $this->processor->save($this->source, $destination, $encoder);
        }

        if ($this->encoder === null) {
            return $this->processor->toWebp($this->source, quality: $this->quality);
        }

        $destination = preg_replace(
            '/\.[^.\/\\\\]+$/',
            '.' . $encoder->extension(),
            $this->source
        );

        if ($destination === $this->source) {
            $destination = $this->source . '.' . $encoder->extension();
        }

        return $this->processor->save($this->source, $destination, $encoder);
    }
}
