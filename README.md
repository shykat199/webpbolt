# WebpBolt — Fast, Modular Image to WebP Converter for PHP

Convert **JPG, PNG, GIF and BMP images to WebP** in PHP, with an extensible
pipeline for resizing, grayscale, and any feature you add. Lightweight, zero
dependencies, built on PHP's native GD extension.

WebP files are typically 25–35% smaller than JPG and PNG, so converting your
images makes websites load faster and improves Core Web Vitals.

## Features

- Convert JPG, JPEG, PNG, GIF and BMP to WebP
- Modular pipeline — chain transforms (resize, grayscale, ...)
- Pluggable encoders — add new output formats (AVIF, JPEG, ...)
- Adjustable quality (0–100), preserves PNG transparency
- No external dependencies — pure PHP + GD

## Requirements

- PHP 8.0 or higher
- GD extension with WebP support

Check WebP support:

```bash
php -r "var_dump(gd_info()['WebP Support']);"
```

## Installation

```bash
composer require shykat199/webpbolt
```

## Usage

### Simple — convert to WebP

```php
use Shykat\WebpBolt\ImageProcessor;

$processor = new ImageProcessor();
$path = $processor->toWebp('photo.jpg');            // -> photo.webp
$path = $processor->toWebp('photo.jpg', 'out.webp', quality: 90);
```

### Modular — chain features, then encode

```php
use Shykat\WebpBolt\ImageProcessor;
use Shykat\WebpBolt\Encoders\WebpEncoder;
use Shykat\WebpBolt\Transforms\Resize;
use Shykat\WebpBolt\Transforms\Grayscale;

$path = (new ImageProcessor())
    ->addTransform(new Resize(800, 800))
    ->addTransform(new Grayscale())
    ->save('photo.jpg', 'output/photo.webp', new WebpEncoder(quality: 75));
```

## Architecture

```
ImageProcessor  ── the conductor
   ├─ ImageLoader           reads input files
   ├─ Transforms/           features that change the image (implement TransformInterface)
   │    ├─ Resize
   │    └─ Grayscale
   └─ Encoders/             output formats (implement EncoderInterface)
        └─ WebpEncoder
```

### Add a new feature

Create one file in `src/Transforms/` implementing `TransformInterface`:

```php
namespace Shykat\WebpBolt\Transforms;

use GdImage;
use Shykat\WebpBolt\Contracts\TransformInterface;

class Watermark implements TransformInterface
{
    public function __construct(private string $text) {}

    public function apply(GdImage $image): GdImage
    {
        $color = imagecolorallocatealpha($image, 255, 255, 255, 50);
        imagestring($image, 5, 10, 10, $this->text, $color);
        return $image;
    }
}
```

Then use it: `->addTransform(new Watermark('© Me'))`. No other file changes.

## Quality guide

| Quality | Use case                        |
|---------|---------------------------------|
| 90–100  | Photography, minimal loss       |
| 75–85   | Websites (recommended default)  |
| 50–70   | Thumbnails, maximum compression |

## License

MIT
