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
- **Watermarking** — stamp text or a logo, nine positions, adjustable opacity
- Modular pipeline — chain transforms (resize, grayscale, watermark, auto-orient)
- Pluggable encoders — add new output formats (AVIF, JPEG, ...)
- Adjustable quality (0–100), preserves PNG transparency
- No external dependencies — pure PHP + GD

## Requirements

- PHP 8.1 or higher
- GD extension with WebP support
- `ext-exif` (optional) — only needed for the `AutoOrient` transform
- FreeType support in GD (optional) — only needed for `.ttf` watermark fonts

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
use Shykat\WebpBolt\WebpBolt;

WebpBolt::make('photo.jpg')->save();                    // -> photo.webp

WebpBolt::make('photo.jpg')
    ->autoOrient()
    ->resize(1600)
    ->watermarkText('(c) 2026 Studio')
    ->quality(85)
    ->save('out.webp');
```

`WebpBolt` is a thin fluent wrapper. The underlying `ImageProcessor` API is
unchanged and still available if you prefer it:

```php
use Shykat\WebpBolt\ImageProcessor;

$processor = new ImageProcessor();

$path = $processor->toWebp('photo.jpg');
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

## Watermarking

Stamp text or a logo onto an image. Two named constructors, then chain only the
things you want to change — every option has a working default.

```php
use Shykat\WebpBolt\WebpBolt;

WebpBolt::make('photo.jpg')->watermarkText('(c) 2026 Studio')->save();
```

That's a complete watermark: white text, bottom-right, 60% opacity, 20px margin.

### Text watermarks

```php
use Shykat\WebpBolt\Transforms\Watermark;
use Shykat\WebpBolt\Enums\Position;

Watermark::text('(c) 2026 Studio')
    ->font(__DIR__ . '/fonts/Inter.ttf', 28)
    ->color('#ffffff')
    ->position(Position::BottomLeft)
    ->opacity(70)
    ->margin(30);
```

Without `font()` this falls back to GD's built-in bitmap font, which needs no
asset files but is small and blocky. For anything customer-facing, pass a real
`.ttf`.

`color()` takes `'#ffffff'`, `'fff'`, `'ffffff'` or `[255, 255, 255]`.

### Image watermarks

```php
Watermark::image('logo.png')
    ->position(Position::BottomRight)
    ->scale(0.2)
    ->opacity(45);
```

Use a PNG with a transparent background. `scale()` is a **fraction of the base
image width**, not a pixel size — `0.2` means the logo is 20% as wide as the
photo. That keeps it proportionate whether you're stamping a thumbnail or a
4000px original; a fixed pixel width would be enormous on one and invisible on
the other.

### Positions

`position()` accepts the `Position` enum or its string value:

| Enum | String |
|------|--------|
| `Position::TopLeft` | `'top-left'` |
| `Position::TopCenter` | `'top-center'` |
| `Position::TopRight` | `'top-right'` |
| `Position::MiddleLeft` | `'middle-left'` |
| `Position::Center` | `'center'` |
| `Position::MiddleRight` | `'middle-right'` |
| `Position::BottomLeft` | `'bottom-left'` |
| `Position::BottomCenter` | `'bottom-center'` |
| `Position::BottomRight` | `'bottom-right'` (default) |

The enum gives you IDE autocompletion and turns a typo into an error instead of
a mis-placed logo. Strings are there for config-driven setups.

### All watermark options

| Method | Default | Applies to | What it does |
|--------|---------|------------|--------------|
| `position($p)` | `BottomRight` | both | Where it sits |
| `opacity(int)` | `60` | both | 0 invisible, 100 solid |
| `margin(int)` | `20` | both | Gap from the edge, in pixels |
| `scale(float)` | `0.18` | image | Width as a fraction of the base image |
| `font(string, int)` | — | text | Path to a `.ttf`, plus point size |
| `color(string\|array)` | white | text | Hex string or RGB array |

### Order matters

Add the watermark **after** the resize:

```php
WebpBolt::make('photo.jpg')
    ->autoOrient()
    ->resize(1600)
    ->transform(Watermark::image('logo.png')->scale(0.2))
    ->save('out.webp');
```

Watermarking first means the logo gets shrunk along with the photo and comes out
tiny and blurry. Transforms run in the order you add them, and nothing enforces
this for you.

### Watermarking a whole folder

Pass a configured processor to `BatchProcessor` and every file gets the same
treatment:

```php
$processor = (new ImageProcessor())
    ->addTransform(new Resize(1600, 1600))
    ->addTransform(Watermark::image('logo.png')->opacity(45));

(new BatchProcessor($processor))
    ->source('public/uploads')
    ->destination('public/webp')
    ->run(new WebpEncoder(80));
```

## Architecture

```
BatchProcessor  ── walks a folder, calls ImageProcessor per file
   └─ BatchResult          counts, sizes, failures, timing

ImageProcessor  ── the conductor for a single image
   ├─ ImageLoader           reads input files
   ├─ Transforms/           features that change the image (TransformInterface)
   │    ├─ Resize
   │    ├─ Grayscale
   │    ├─ AutoOrient       fixes sideways phone photos from EXIF
   │    └─ Watermark        text or logo, nine positions
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
- **EXIF orientation is not handled by default.** Photos straight off a phone
  come out rotated unless you add the `AutoOrient` transform, which reads the
  EXIF flag and rotates for you. Add it first in the chain.
- **Metadata is not preserved** — EXIF, ICC colour profiles and copyright tags
  are dropped by GD. Good for file size and privacy, bad if you need them.
- **Memory**: GD decodes to roughly `width × height × 4` bytes. Batch mode refuses
  oversized images rather than letting PHP die with an uncatchable fatal, but a
  single very large image can still exceed `memory_limit` on the transform step.

## License

MIT
## Laravel

The package auto-registers when installed in a Laravel app. Publish the config:

```bash
php artisan vendor:publish --tag=webpbolt-config
```

Set your defaults once in `config/webpbolt.php`:

```php
'max_dimension' => 1600,
'quality'       => 80,

'watermark' => [
    'image'    => public_path('logo.png'),
    'position' => 'bottom-right',
    'opacity'  => 50,
    'scale'    => 0.18,
],
```

Then convert a folder from the command line:

```bash
php artisan images:webp --dry            # preview, writes nothing
php artisan images:webp                  # convert
php artisan images:webp --force          # rebuild everything
php artisan images:webp --no-watermark   # skip the configured watermark
```

An `ImageProcessor` pre-configured from that config is bound in the container,
so injecting it anywhere gives you the resize and watermark for free:

```php
public function store(Request $request, ImageProcessor $processor)
{
    $processor->toWebp($request->file('photo')->getRealPath());
}
```

Put it on a schedule to catch new uploads. Repeat runs cost one filesystem stat
per file, so this is cheap:

```php
Schedule::command('images:webp')->hourly()->withoutOverlapping();
```

## Errors

Everything thrown extends `WebpBoltException`, which extends `RuntimeException`
— so existing `catch (RuntimeException)` blocks keep working while new code can
catch precisely:

```php
use Shykat\WebpBolt\Exceptions\ImageNotFoundException;
use Shykat\WebpBolt\Exceptions\ImageTooLargeException;
use Shykat\WebpBolt\Exceptions\UnsupportedFormatException;
use Shykat\WebpBolt\Exceptions\EncodingFailedException;

try {
    WebpBolt::make($path)->resize(1600)->save();
} catch (UnsupportedFormatException $e) {
    // not an image we can read
} catch (ImageTooLargeException $e) {
    // would blow the memory limit
}
```

`BatchProcessor` catches these per file and collects them in `$result->failed`,
so one bad image never stops a run.

## Testing

```bash
composer install
composer test
```
