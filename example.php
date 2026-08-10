<?php

declare(strict_types=1);

require __DIR__ . '/vendor/autoload.php';

use Shykat\WebpBolt\ImageProcessor;
use Shykat\WebpBolt\Encoders\WebpEncoder;
use Shykat\WebpBolt\Transforms\Resize;
use Shykat\WebpBolt\Transforms\Grayscale;

// --- Simple: just convert to WebP ---
$processor = new ImageProcessor();
$out = $processor->toWebp(__DIR__ . '/photo.jpg');
echo "Saved: {$out}\n";

// --- Modular: chain features, then encode ---
$out = (new ImageProcessor())
    ->addTransform(new Resize(800, 800))   // feature 1
    ->addTransform(new Grayscale())        // feature 2
    ->save(
        __DIR__ . '/photo.jpg',
        __DIR__ . '/output/photo.webp',
        new WebpEncoder(quality: 75)
    );

echo "Saved (resized + grayscale): {$out}\n";
