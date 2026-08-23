<?php

declare(strict_types=1);

namespace Shykat\WebpBolt\Laravel;

use Illuminate\Console\Command;
use Shykat\WebpBolt\BatchProcessor;
use Shykat\WebpBolt\Encoders\WebpEncoder;
use Shykat\WebpBolt\ImageProcessor;
use Shykat\WebpBolt\Transforms\Resize;
use Symfony\Component\Console\Helper\ProgressBar;

/**
 * php artisan images:webp --dry
 * php artisan images:webp --quality=85
 * php artisan images:webp --force
 */
class ConvertImagesCommand extends Command
{
    protected $signature = 'images:webp
        {--source= : Folder to convert, defaults to config}
        {--destination= : Where to write, defaults to beside each original}
        {--quality= : Output quality 0-100}
        {--resize= : Max width and height in pixels, 0 to disable}
        {--dry : Count and measure without writing anything}
        {--force : Reconvert files that are already up to date}
        {--no-watermark : Skip the watermark configured in config/webpbolt.php}';

    protected $description = 'Convert images in a folder to WebP';

    public function handle(): int
    {
        $source = $this->option('source') ?: config('webpbolt.source');

        if (!$source || !is_dir($source)) {
            $this->error("Source folder not found: " . ($source ?: '(not set)'));

            return self::FAILURE;
        }

        $quality = (int) ($this->option('quality') ?? config('webpbolt.quality'));
        $max     = (int) ($this->option('resize') ?? config('webpbolt.max_dimension'));

        $processor = new ImageProcessor();

        if ($max > 0) {
            $processor->addTransform(new Resize($max, $max));
        }

        if (!$this->option('no-watermark')) {
            $watermark = WatermarkFactory::fromConfig(config('webpbolt.watermark', []));

            if ($watermark !== null) {
                $processor->addTransform($watermark);
            }
        }

        $bar = null;

        $batch = (new BatchProcessor($processor))
            ->source($source)
            ->destination($this->option('destination') ?: config('webpbolt.destination'))
            ->dryRun((bool) $this->option('dry'))
            ->skipExisting(!$this->option('force'))
            ->countFirst()
            ->onProgress(function (string $file, int $done, int $total) use (&$bar): void {
                if ($bar === null) {
                    $bar = $this->output->createProgressBar($total);
                    $bar->start();
                }

                $bar->setProgress($done);
            });

        if ($this->option('dry')) {
            $this->comment('Dry run — nothing will be written.');
        }

        $result = $batch->run(new WebpEncoder($quality));

        if ($bar instanceof ProgressBar) {
            $bar->finish();
        }

        $this->newLine(2);
        $this->info($result->summary());

        if ($result->failed !== []) {
            $this->newLine();
            $this->warn(count($result->failed) . ' file(s) failed:');

            foreach ($result->failed as $path => $error) {
                $this->line("  <fg=red>{$error}</> {$path}");
            }
        }

        return self::SUCCESS;
    }
}
