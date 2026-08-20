# WebpBolt — Fast, Modular Image to WebP Converter for PHP

Convert **JPG, PNG, GIF and BMP images to WebP** in PHP — one file at a time, or
an entire directory tree in a single call. Lightweight, zero dependencies, built
on PHP's native GD extension.

WebP files are typically 25–35% smaller than JPG and PNG, so converting your
images makes websites load faster and improves Core Web Vitals.

```php
$result = (new BatchProcessor())
    ->source('public/uploads')
    ->destination('public/webp')
    ->run();

echo $result->summary();
// 412 converted, 88 skipped, 2 failed — 31.4MB saved (36.2%) in 47.9s
```

## Features

- Convert JPG, JPEG, PNG, GIF and BMP to WebP
- **Batch mode** — convert a whole folder tree, with progress reporting and a dry-run preview
- **Resumable** — already-converted files are skipped on repeat runs, so it's safe on a cron
- **Never makes things worse** — output larger than the original is discarded automatically
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

## Quick start

### Convert one image

```php
use Shykat\WebpBolt\ImageProcessor;

$processor = new ImageProcessor();

$path = $processor->toWebp('photo.jpg');                      // -> photo.webp
$path = $processor->toWebp('photo.jpg', 'out.webp', quality: 90);
```

### Convert a whole folder

```php
use Shykat\WebpBolt\BatchProcessor;

$result = (new BatchProcessor())
    ->source('public/uploads')
    ->destination('public/webp')
    ->run();

echo $result->summary();
```

That's the common case. Everything below is for when you need more control.

---

## Batch conversion

### Preview before you commit

On a production folder, always dry-run first. It walks the tree and counts
everything without writing a single file:

```php
$preview = (new BatchProcessor())
    ->source('public/uploads')
    ->dryRun()
    ->run();

echo "Would convert {$preview->converted} files\n";
```

### Watch the progress

Pass a callback to get told about each file as it's handled. Add `countFirst()`
if you want a real total in the output — it costs one extra pass over the folder:

```php
$result = (new BatchProcessor())
    ->source('public/uploads')
    ->destination('public/webp')
    ->countFirst()
    ->onProgress(function ($file, $done, $total, $status) {
        printf("[%d/%d] %-18s %s\n", $done, $total, $status, basename($file));
    })
    ->run();
```

```
[1/500] converted         hero-banner.jpg
[2/500] skipped           logo.png
[3/500] skipped (larger)  icon-optimised.jpg
[4/500] converted         team-photo.jpg
```

### Resize everything while converting

Pass a pre-configured `ImageProcessor` and its transforms apply to every file in
the batch:

```php
use Shykat\WebpBolt\ImageProcessor;
use Shykat\WebpBolt\Transforms\Resize;

$processor = (new ImageProcessor())
    ->addTransform(new Resize(1600, 1600));

$result = (new BatchProcessor($processor))
    ->source('public/uploads')
    ->destination('public/webp')
    ->run(new WebpEncoder(quality: 78));
```

### Convert in place

Leave the destination unset and each `.webp` is written next to its original:

```php
(new BatchProcessor())->source('public/uploads')->run();

// public/uploads/2024/cat.jpg
// public/uploads/2024/cat.webp   <- new
```

### Handle failures

The batch never stops on a bad file. Errors are collected and handed back:

```php
$result = (new BatchProcessor())->source('public/uploads')->run();

foreach ($result->failed as $path => $error) {
    fwrite(STDERR, "FAILED {$path}: {$error}\n");
}
```

### Run it from cron

Repeat runs are cheap — the skip check reads file timestamps and never decodes
an image, so an already-converted tree of 40,000 files finishes in seconds:

```bash
*/15 * * * * php /var/www/site/bin/convert-images.php >> /var/log/webpbolt.log 2>&1
```

### All batch options

| Method | Default | What it does |
|--------|---------|--------------|
| `source(string $dir)` | *required* | Folder to read from |
| `destination(?string $dir)` | `null` | Where to write. `null` writes beside each original |
| `recursive(bool)` | `true` | Include subfolders |
| `skipExisting(bool)` | `true` | Skip files already converted and up to date |
| `keepSmaller(bool)` | `true` | Discard output that came out bigger than the source |
| `dryRun(bool)` | `false` | Count and measure, write nothing |
| `countFirst(bool)` | `false` | Pre-count the tree so progress shows a total |
| `maxPixels(int)` | ~64M | Refuse images above this pixel count. `0` disables |
| `onProgress(callable)` | — | `fn($file, $done, $total, $status)` |

Turn `skipExisting` off to force a full rebuild — for example after changing the
quality setting:

```php
(new BatchProcessor())
    ->source('public/uploads')
    ->skipExisting(false)
    ->run(new WebpEncoder(quality: 65));
```

### Reading the result

`run()` returns a `BatchResult`:

```php
$result->converted;       // int   — files successfully written
$result->skipped;         // int   — up to date, or output was larger
$result->failed;          // array — [source path => error message]
$result->bytesIn;         // int   — total original size
$result->bytesOut;        // int   — total new size
$result->bytesSaved();    // int
$result->percentSaved();  // float
$result->seconds;         // float
$result->total();         // int   — every file looked at
$result->summary();       // string — the one-line report
```

---

## Single-image pipeline

### Chain transforms, then encode

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

`Resize` fits the image inside the given box while keeping its aspect ratio, and
only ever shrinks — passing a box larger than the image leaves it untouched.

Note that transforms stay attached to the processor, so reusing an instance
applies them again to the next file. That's exactly what you want when feeding it
to `BatchProcessor`; create a fresh `ImageProcessor` when you don't.

## Architecture

```
BatchProcessor  ── walks a folder, calls ImageProcessor per file
   └─ BatchResult          counts, sizes, failures, timing

ImageProcessor  ── the conductor for a single image
   ├─ ImageLoader           reads input files
   ├─ Transforms/           features that change the image (TransformInterface)
   │    ├─ Resize
   │    └─ Grayscale
   └─ Encoders/             output formats (EncoderInterface)
        └─ WebpEncoder
```

### Add a new transform

One file in `src/Transforms/` implementing `TransformInterface`:

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

A transform may return a different `GdImage` than it was given — `Resize` does,
because it has to build a new canvas. Treat the handle you passed in as invalid
once `apply()` has returned.

### Add a new output format

One file in `src/Encoders/` implementing `EncoderInterface`:

```php
namespace Shykat\WebpBolt\Encoders;

use GdImage;
use Shykat\WebpBolt\Contracts\EncoderInterface;

class AvifEncoder implements EncoderInterface
{
    public function __construct(private int $quality = 60) {}

    public function encode(GdImage $image, string $destinationPath): bool
    {
        imagepalettetotruecolor($image);
        imagealphablending($image, false);
        imagesavealpha($image, true);

        return imageavif($image, $destinationPath, $this->quality);
    }

    public function extension(): string
    {
        return 'avif';
    }
}
```

It then works everywhere an encoder is accepted, batch included:

```php
(new BatchProcessor())->source('public/uploads')->run(new AvifEncoder(60));
```

## Quality guide

| Quality | Use case |
|---------|----------|
| 90–100 | Photography, minimal loss |
| 75–85 | Websites (recommended default) |
| 50–70 | Thumbnails, maximum compression |

## Notes and limitations

- **Animated GIFs** lose everything but the first frame. GD can't read the rest.
- **EXIF orientation is ignored**, so photos straight off a phone may come out
  rotated. Correct them before converting until an `AutoOrient` transform lands.
- **Metadata is not preserved** — EXIF, ICC colour profiles and copyright tags
  are dropped by GD. Good for file size and privacy, bad if you need them.
- **Memory**: GD decodes to roughly `width × height × 4` bytes. Batch mode refuses
  oversized images rather than letting PHP die with an uncatchable fatal, but a
  single very large image can still exceed `memory_limit` on the transform step.

## License

MIT