<?php

declare(strict_types=1);

namespace Shykat\WebpBolt\Laravel;

use Illuminate\Support\ServiceProvider;
use Shykat\WebpBolt\ImageProcessor;
use Shykat\WebpBolt\Transforms\Resize;

/**
 * Optional Laravel integration.
 *
 * This class is only ever loaded inside a Laravel app. illuminate/support
 * is a require-dev / suggest dependency, never a hard one, so the package
 * still installs cleanly in a plain PHP project where this file is simply
 * never touched.
 */
class WebpBoltServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__ . '/../../config/webpbolt.php', 'webpbolt');

        // bind, not singleton: transforms accumulate on an ImageProcessor
        // instance, so a shared one would leak them between callers.
        $this->app->bind(ImageProcessor::class, function ($app) {
            $processor = new ImageProcessor();

            $max = $app['config']->get('webpbolt.max_dimension');

            if ($max) {
                $processor->addTransform(new Resize($max, $max));
            }

            // After the resize, so the logo is stamped at final size.
            $watermark = WatermarkFactory::fromConfig(
                $app['config']->get('webpbolt.watermark', [])
            );

            if ($watermark !== null) {
                $processor->addTransform($watermark);
            }

            return $processor;
        });
    }

    public function boot(): void
    {
        if (!$this->app->runningInConsole()) {
            return;
        }

        $this->publishes([
            __DIR__ . '/../../config/webpbolt.php' => $this->app->configPath('webpbolt.php'),
        ], 'webpbolt-config');

        $this->commands([ConvertImagesCommand::class]);
    }
}
