<?php

declare(strict_types=1);

return [

    /*
    |--------------------------------------------------------------------------
    | Default source folder
    |--------------------------------------------------------------------------
    | Used by `php artisan images:webp` when --source is not passed.
    */

    'source' => storage_path('app/public/uploads'),

    /*
    |--------------------------------------------------------------------------
    | Default destination
    |--------------------------------------------------------------------------
    | null writes each .webp beside its original, preserving the folder tree.
    */

    'destination' => null,

    /*
    |--------------------------------------------------------------------------
    | Output quality (0-100)
    |--------------------------------------------------------------------------
    | 75-85 is the sweet spot for web images. Higher for photography.
    */

    'quality' => 80,

    /*
    |--------------------------------------------------------------------------
    | Maximum dimension in pixels
    |--------------------------------------------------------------------------
    | No side of an image will exceed this. Aspect ratio is kept and images
    | are never enlarged. Set to 0 to disable resizing entirely.
    */

    'max_dimension' => 1600,

    /*
    |--------------------------------------------------------------------------
    | Watermark
    |--------------------------------------------------------------------------
    | Leave 'text' and 'image' both null to disable watermarking. If both are
    | set, the image wins. Position accepts any value of the Position enum:
    | top-left, top-center, top-right, middle-left, center, middle-right,
    | bottom-left, bottom-center, bottom-right.
    */

    'watermark' => [
        'text'     => null,
        'image'    => null,
        'position' => 'bottom-right',
        'opacity'  => 60,
        'margin'   => 20,
        'scale'    => 0.18,
        'font'     => null,
        'size'     => 20,
        'color'    => '#ffffff',
    ],

];
