<?php

declare(strict_types=1);

use Shykat\WebpBolt\Encoders\WebpEncoder;
use Shykat\WebpBolt\ImageProcessor;
use Shykat\WebpBolt\Transforms\Grayscale;
use Shykat\WebpBolt\Transforms\Resize;
use Shykat\WebpBolt\WebpBolt;

/*
| No fixture files are needed. imagecreatetruecolor() makes test images in
| memory, which keeps the repo small and the tests fast.
*/

function tmpJpeg(int $width, int $height): string
{
    $path  = sys_get_temp_dir() . '/wb-' . uniqid() . '.jpg';
    $image = imagecreatetruecolor($width, $height);

    imagefill($image, 0, 0, imagecolorallocate($image, 200, 40, 40));
    imagejpeg($image, $path, 92);
    imagedestroy($image);

    return $path;
}

it('fits a wide image inside the box and keeps the ratio', function () {
    $result = (new Resize(1600, 1600))->apply(imagecreatetruecolor(4000, 3000));

    expect(imagesx($result))->toBe(1600)
        ->and(imagesy($result))->toBe(1200);
});

it('fits a tall image inside the box', function () {
    $result = (new Resize(1600, 1600))->apply(imagecreatetruecolor(3000, 4000));

    expect(imagesx($result))->toBe(1200)
        ->and(imagesy($result))->toBe(1600);
});

it('never enlarges an image that already fits', function () {
    $result = (new Resize(1600, 1600))->apply(imagecreatetruecolor(900, 600));

    expect(imagesx($result))->toBe(900)
        ->and(imagesy($result))->toBe(600);
});

it('honours a non-square box', function () {
    $result = (new Resize(1200, 400))->apply(imagecreatetruecolor(2000, 2000));

    expect(imagesx($result))->toBe(400)
        ->and(imagesy($result))->toBe(400);
});

it('removes colour with grayscale', function () {
    $image = imagecreatetruecolor(10, 10);
    imagefill($image, 0, 0, imagecolorallocate($image, 200, 40, 40));

    $result = (new Grayscale())->apply($image);
    $rgb    = imagecolorsforindex($result, imagecolorat($result, 5, 5));

    expect($rgb['red'])->toBe($rgb['green'])
        ->and($rgb['green'])->toBe($rgb['blue']);
});

it('writes a real webp file', function () {
    $source = tmpJpeg(800, 600);
    $out    = sys_get_temp_dir() . '/wb-out-' . uniqid() . '.webp';

    (new ImageProcessor())->save($source, $out, new WebpEncoder(80));

    expect(file_exists($out))->toBeTrue()
        ->and(getimagesize($out)[2])->toBe(IMAGETYPE_WEBP);

    unlink($source);
    unlink($out);
});

it('runs the whole pipeline through the fluent api', function () {
    $source = tmpJpeg(3000, 2000);

    $out = WebpBolt::make($source)->resize(1000)->grayscale()->quality(70)->save();

    expect(getimagesize($out)[0])->toBe(1000)
        ->and(getimagesize($out)[1])->toBe(667);

    unlink($source);
    unlink($out);
});

it('rejects an out of range quality', function () {
    new WebpEncoder(140);
})->throws(InvalidArgumentException::class);

it('throws when the source file is missing', function () {
    (new ImageProcessor())->toWebp('/no/such/file.jpg');
})->throws(InvalidArgumentException::class);
