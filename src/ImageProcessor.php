<?php

declare(strict_types=1);

namespace Shykat\WebpBolt;

use RuntimeException;
use Shykat\WebpBolt\Contracts\EncoderInterface;
use Shykat\WebpBolt\Contracts\TransformInterface;
use Shykat\WebpBolt\Encoders\WebpEncoder;

/**
 * Main entry point. Loads an image, applies a pipeline of transforms,
 * then encodes the result to disk.
 */
class ImageProcessor
{
    private ImageLoader $loader;

    /** @var TransformInterface[] */
    private array $transforms = [];

    public function __construct(?ImageLoader $loader = null)
    {
        if (!extension_loaded('gd')) {
            throw new RuntimeException('The GD extension is required.');
        }

        $this->loader = $loader ?? new ImageLoader();
    }

    /**
     * Add a feature to the pipeline (resize, grayscale, watermark, ...).
     * Returns $this so calls can be chained.
     */
    public function addTransform(TransformInterface $transform): self
    {
        $this->transforms[] = $transform;

        return $this;
    }

    /**
     * Full control: load -> run all transforms -> encode with the given encoder.
     *
     * @return string The path of the created file.
     */
    public function save(string $sourcePath, string $destinationPath, EncoderInterface $encoder): string
    {
        $image = $this->loader->load($sourcePath);

        foreach ($this->transforms as $transform) {
            $image = $transform->apply($image);
        }

        $dir = dirname($destinationPath);
        if (!is_dir($dir) && !mkdir($dir, 0775, true) && !is_dir($dir)) {
            imagedestroy($image);
            throw new RuntimeException("Could not create directory: {$dir}");
        }

        $ok = $encoder->encode($image, $destinationPath);
        imagedestroy($image);

        if (!$ok) {
            throw new RuntimeException('Failed to write the output file.');
        }

        return $destinationPath;
    }

    /**
     * Convenience shortcut for the most common case: convert to WebP.
     * Keeps simple usage a one-liner.
     */
    public function toWebp(string $sourcePath, ?string $destinationPath = null, int $quality = 80): string
    {
        $encoder = new WebpEncoder($quality);
        $destinationPath ??= preg_replace('/\.[^.\/\\\\]+$/', '.' . $encoder->extension(), $sourcePath);

        return $this->save($sourcePath, $destinationPath, $encoder);
    }
}
