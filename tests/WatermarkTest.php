<?php

declare(strict_types=1);

use Shykat\WebpBolt\Enums\Position;
use Shykat\WebpBolt\Exceptions\ImageNotFoundException;
use Shykat\WebpBolt\Exceptions\WebpBoltException;
use Shykat\WebpBolt\Transforms\Watermark;

function solidImage(int $width, int $height, array $rgb = [0, 0, 0]): GdImage
{
    $image = imagecreatetruecolor($width, $height);
    imagefill($image, 0, 0, imagecolorallocate($image, ...$rgb));

    return $image;
}

function logoFile(int $width = 100, int $height = 50): string
{
    $path  = sys_get_temp_dir() . '/wb-logo-' . uniqid() . '.png';
    $stamp = imagecreatetruecolor($width, $height);

    imagealphablending($stamp, false);
    imagesavealpha($stamp, true);
    imagefill($stamp, 0, 0, imagecolorallocate($stamp, 255, 0, 0));
    imagepng($stamp, $path);
    imagedestroy($stamp);

    return $path;
}

it('changes pixels where the text lands', function () {
    $image = solidImage(400, 300);

    Watermark::text('TEST')->position(Position::TopLeft)->opacity(100)->apply($image);

    // Scan the top-left region for any pixel that is no longer pure black.
    $found = false;

    for ($x = 20; $x < 120 && !$found; $x++) {
        for ($y = 20; $y < 60; $y++) {
            if (imagecolorat($image, $x, $y) !== imagecolorat($image, 300, 250)) {
                $found = true;
                break;
            }
        }
    }

    expect($found)->toBeTrue();
});

it('scales an image watermark relative to the canvas width', function () {
    $logo  = logoFile(200, 100);
    $image = solidImage(1000, 800);

    Watermark::image($logo)->scale(0.2)->opacity(100)->position(Position::TopLeft)->apply($image);

    // 20% of 1000 = 200px wide, so a pixel well inside that box is red.
    $rgb = imagecolorsforindex($image, imagecolorat($image, 100, 40));

    expect($rgb['red'])->toBeGreaterThan(200)
        ->and($rgb['green'])->toBeLessThan(50);

    unlink($logo);
});

it('places the watermark in the requested corner', function () {
    $logo  = logoFile(100, 100);
    $image = solidImage(600, 600);

    Watermark::image($logo)
        ->scale(0.2)->opacity(100)->margin(10)
        ->position(Position::BottomRight)
        ->apply($image);

    $bottomRight = imagecolorsforindex($image, imagecolorat($image, 540, 540));
    $topLeft     = imagecolorsforindex($image, imagecolorat($image, 20, 20));

    expect($bottomRight['red'])->toBeGreaterThan(200)
        ->and($topLeft['red'])->toBe(0);

    unlink($logo);
});

it('accepts the position as a plain string', function () {
    $image = solidImage(200, 200);

    Watermark::text('X')->position('top-right')->apply($image);
})->throwsNoExceptions();

it('parses hex colours in both long and short form', function () {
    $image = solidImage(200, 200);

    Watermark::text('X')->color('#fff')->apply($image);
    Watermark::text('X')->color('ff8800')->apply($image);
    Watermark::text('X')->color([12, 34, 56])->apply($image);
})->throwsNoExceptions();

it('rejects a bad hex colour', function () {
    Watermark::text('X')->color('nothex');
})->throws(WebpBoltException::class);

it('rejects opacity outside 0-100', function () {
    Watermark::text('X')->opacity(140);
})->throws(WebpBoltException::class);

it('rejects a scale outside 0-1', function () {
    Watermark::image('logo.png')->scale(2.5);
})->throws(WebpBoltException::class);

it('reports a missing watermark file clearly', function () {
    Watermark::image('/no/such/logo.png')->apply(solidImage(100, 100));
})->throws(ImageNotFoundException::class);

it('reports a missing font file clearly', function () {
    Watermark::text('X')->font('/no/such/font.ttf');
})->throws(ImageNotFoundException::class);

it('computes offsets for every position', function () {
    // 1000x800 canvas, 100x50 box, 20px margin
    expect(Position::TopLeft->offset(1000, 800, 100, 50, 20))->toBe([20, 20])
        ->and(Position::BottomRight->offset(1000, 800, 100, 50, 20))->toBe([880, 730])
        ->and(Position::Center->offset(1000, 800, 100, 50, 20))->toBe([450, 375])
        ->and(Position::TopCenter->offset(1000, 800, 100, 50, 20))->toBe([450, 20]);
});

it('clamps to zero when the watermark is bigger than the image', function () {
    expect(Position::BottomRight->offset(100, 100, 400, 400, 20))->toBe([0, 0]);
});
