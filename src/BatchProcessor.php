<?php

declare(strict_types=1);

namespace Shykat\WebpBolt;

use FilesystemIterator;
use Generator;
use InvalidArgumentException;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use RuntimeException;
use Shykat\WebpBolt\Contracts\EncoderInterface;
use Shykat\WebpBolt\Encoders\WebpEncoder;
use SplFileInfo;
use Throwable;

/**
 * Converts every supported image in a folder (and its subfolders).
 *
 * WHAT THIS IS FOR
 * ImageProcessor handles one file. This handles a whole directory tree —
 * the "I have 40,000 old JPEGs on my server" case.
 *
 * HOW YOU USE IT
 * Every setter returns $this, so you chain them and finish with run():
 *
 *   $result = (new BatchProcessor())
 *       ->source('public/uploads')      // where to read from
 *       ->destination('public/webp')    // where to write to
 *       ->run(new WebpEncoder(80));     // go
 *
 *   echo $result->summary();
 *
 * WHY IT'S BUILT THIS WAY
 * Two things matter at scale: memory and re-runs.
 *
 * Memory — files are streamed one at a time with a generator, never
 * collected into an array. A tree with a million files uses the same
 * memory as one with ten.
 *
 * Re-runs — the "has this already been converted?" check happens on file
 * timestamps, before we decode any pixels. So running this a second time
 * over the same folder costs one stat() per file and finishes in seconds.
 * That's what makes it safe to put on a cron job.
 */
class BatchProcessor
{
    /**
     * Which file extensions we'll try to open.
     *
     * Stored as a map (not a plain list) so the lookup below can use
     * isset(), which is a hash lookup rather than a scan through an array.
     * At 40k files that difference is worth having.
     */
    private const EXTENSIONS = [
        'jpg' => true, 'jpeg' => true, 'png' => true,
        'gif' => true, 'bmp' => true,
    ];

    /** Folder we read from. Always an absolute, resolved path. */
    private string $source;

    /** Folder we write to. Null means "write next to each original". */
    private ?string $destination = null;

    /** Descend into subfolders, or just read the top level. */
    private bool $recursive = true;

    /** Skip files whose output already exists and is newer than the source. */
    private bool $skipExisting = true;

    /** Throw away the output if it came out bigger than the original. */
    private bool $keepSmaller = true;

    /** Walk and measure, but never write anything. */
    private bool $dryRun = false;

    /** Walk the tree once up front just to count it, so progress shows a total. */
    private bool $countFirst = false;

    /** Refuse images bigger than this many pixels. 0 disables the check. */
    private int $maxPixels = 0;

    /** Optional callback fired after each file. @var callable|null */
    private $onProgress;

    /**
     * @param ImageProcessor $processor The single-file converter that does the
     *                                  actual work. Pass your own if you want
     *                                  transforms (resize, grayscale, ...)
     *                                  applied to every file in the batch.
     */
    public function __construct(
        private ImageProcessor $processor = new ImageProcessor()
    ) {
        // Default safety ceiling: roughly a 256MB decoded bitmap.
        //
        // GD needs about width x height x 4 bytes of RAM to open an image,
        // and if it runs out PHP dies with a fatal error you cannot catch —
        // killing the entire batch. Converting that into a per-file
        // exception means one monster image doesn't stop the other 39,999.
        $this->maxPixels = (int) (256 * 1024 * 1024 / 4);
    }

    /**
     * Set the folder to read from. Required.
     *
     * We resolve it to an absolute path immediately, because
     * destinationFor() later slices this string off the front of each file
     * path to work out the relative sub-path. That only works if both
     * strings are in the same form.
     */
    public function source(string $dir): self
    {
        $real = realpath($dir);

        if ($real === false || !is_dir($real)) {
            throw new InvalidArgumentException("Source directory not found: {$dir}");
        }

        $this->source = $real;

        return $this;
    }

    /**
     * Set the folder to write to.
     *
     * The subfolder structure of the source is recreated inside it, so
     * uploads/2024/june/cat.jpg becomes webp/2024/june/cat.webp.
     *
     * Pass null (the default) to write each .webp right beside its original.
     */
    public function destination(?string $dir): self
    {
        $this->destination = $dir !== null ? rtrim($dir, '/\\') : null;

        return $this;
    }

    /** Include subfolders. On by default. */
    public function recursive(bool $v = true): self
    {
        $this->recursive = $v;

        return $this;
    }

    /**
     * Skip files that are already converted and up to date.
     *
     * On by default, and it's what makes repeat runs cheap. Turn it off to
     * force everything to be rebuilt — for example after you change the
     * quality setting.
     */
    public function skipExisting(bool $v = true): self
    {
        $this->skipExisting = $v;

        return $this;
    }

    /**
     * Delete the output if it turned out bigger than the original.
     *
     * WebP usually wins, but not always — an already well-optimised JPEG
     * can beat it. Without this you'd quietly make some pages slower.
     */
    public function keepSmaller(bool $v = true): self
    {
        $this->keepSmaller = $v;

        return $this;
    }

    /**
     * Preview mode: count the files and their size, write nothing.
     *
     * Run this first on anything in production so you can see how many
     * files will change before you commit to it.
     */
    public function dryRun(bool $v = true): self
    {
        $this->dryRun = $v;

        return $this;
    }

    /**
     * Count the tree before starting, so your progress callback gets a real
     * total instead of 0.
     *
     * Costs one extra pass over the directory. Fine for most folders; leave
     * it off for very large or slow (network) ones.
     */
    public function countFirst(bool $v = true): self
    {
        $this->countFirst = $v;

        return $this;
    }

    /**
     * Change the maximum image size we'll attempt, measured in pixels
     * (width x height). Pass 0 to remove the limit entirely.
     */
    public function maxPixels(int $pixels): self
    {
        $this->maxPixels = $pixels;

        return $this;
    }

    /**
     * Get told about each file as it's handled — for progress bars or logs.
     *
     * Your callback receives:
     *   string $file    full path of the file just handled
     *   int    $done    how many files processed so far
     *   int    $total   total files, or 0 unless you called countFirst()
     *   string $status  'converted', 'skipped', 'skipped (larger)',
     *                   'would convert', or 'failed'
     */
    public function onProgress(callable $cb): self
    {
        $this->onProgress = $cb;

        return $this;
    }

    /**
     * Run the batch.
     *
     * @param EncoderInterface|null $encoder Output format. Defaults to WebP
     *                                       at quality 80.
     */
    public function run(?EncoderInterface $encoder = null): BatchResult
    {
        $encoder ??= new WebpEncoder(80);
        $result    = new BatchResult();
        $started   = microtime(true);
        $ext       = $encoder->extension();

        $total = $this->countFirst ? $this->count($ext) : 0;
        $done  = 0;

        foreach ($this->files($ext) as $file) {
            $done++;
            $source = $file->getPathname();
            $status = 'converted';

            // One file failing must not kill the run, so every file is
            // wrapped. The error goes into the report and we move on.
            try {
                $status = $this->handle($file, $encoder, $result);
            } catch (Throwable $e) {
                $result->failed[$source] = $e->getMessage();
                $status = 'failed';
            }

            if ($this->onProgress !== null) {
                ($this->onProgress)($source, $done, $total, $status);
            }
        }

        $result->seconds = round(microtime(true) - $started, 2);

        return $result;
    }

    /**
     * Handle exactly one file, and say what happened to it.
     *
     * The four steps below are ordered cheapest-first on purpose. Most
     * files in a repeat run never get past step 1, and a bad file never
     * gets past step 2 — so we only pay the expensive decode in step 3 for
     * files that genuinely need converting.
     */
    private function handle(
        SplFileInfo $file,
        EncoderInterface $encoder,
        BatchResult $result
    ): string {
        $source     = $file->getPathname();
        $dest       = $this->destinationFor($source, $encoder->extension());
        $sourceSize = $file->getSize();
        $sourceTime = $file->getMTime();

        // STEP 1 — Already done?
        // If the output exists and is at least as new as the source, there's
        // nothing to do. Costs one filesystem stat, no image decoding.
        if ($this->skipExisting && is_file($dest) && filemtime($dest) >= $sourceTime) {
            $result->skipped++;

            return 'skipped';
        }

        // STEP 2 — Is it really an image, and is it a sane size?
        // getimagesize() reads only the file header, so we learn the
        // dimensions without allocating the full bitmap in memory.
        // The @ suppresses PHP's warning on unreadable files; we throw our
        // own clearer exception instead.
        $info = @getimagesize($source);

        if ($info === false) {
            throw new RuntimeException('Not a readable image.');
        }

        if ($this->maxPixels > 0 && ($info[0] * $info[1]) > $this->maxPixels) {
            throw new RuntimeException(
                sprintf('Image too large to decode safely (%dx%d).', $info[0], $info[1])
            );
        }

        // In preview mode we stop here — we know enough to report on the
        // file without writing anything to disk.
        if ($this->dryRun) {
            $result->converted++;
            $result->bytesIn += $sourceSize;

            return 'would convert';
        }

        // STEP 3 — The expensive bit: decode, transform, encode, write.
        $this->processor->save($source, $dest, $encoder);

        // PHP caches filesystem results, so without clearing it filesize()
        // can return a stale value for the file we just created.
        clearstatcache(true, $dest);
        $outSize = filesize($dest);

        // STEP 4 — Was it actually worth it?
        // If the new file isn't smaller, throw it away and keep the
        // original. Better to do nothing than to make things worse.
        if ($this->keepSmaller && $outSize >= $sourceSize) {
            @unlink($dest);
            $result->skipped++;

            return 'skipped (larger)';
        }

        $result->converted++;
        $result->bytesIn  += $sourceSize;
        $result->bytesOut += $outSize;

        return 'converted';
    }

    /**
     * Walk the source folder and hand back the image files, one at a time.
     *
     * This is a generator (note the `yield` rather than `return`), which
     * means it produces files lazily as the loop in run() asks for them.
     * Nothing is ever held in an array, so memory stays flat no matter how
     * big the folder is.
     *
     * @param string $outputExt Extension the encoder produces — we use it to
     *                          avoid reading back our own output.
     *
     * @return Generator<SplFileInfo>
     */
    private function files(string $outputExt): Generator
    {
        $flags = FilesystemIterator::SKIP_DOTS | FilesystemIterator::CURRENT_AS_FILEINFO;

        $iterator = $this->recursive
            ? new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator($this->source, $flags),
                RecursiveIteratorIterator::LEAVES_ONLY   // folders themselves aren't yielded
            )
            : new FilesystemIterator($this->source, $flags);

        // If the destination sits inside the source tree, the walk would
        // otherwise find the files we just wrote and try to convert those
        // too. This prefix lets us recognise and skip them.
        $destPrefix = $this->destination !== null
            ? $this->destination . DIRECTORY_SEPARATOR
            : null;

        foreach ($iterator as $file) {
            /** @var SplFileInfo $file */
            if (!$file->isFile()) {
                continue;
            }

            $ext = strtolower($file->getExtension());

            // Not an image we handle — or it IS our own output format, which
            // means converting it would be pointless work.
            if (!isset(self::EXTENSIONS[$ext]) || $ext === $outputExt) {
                continue;
            }

            if ($destPrefix !== null && str_starts_with($file->getPathname(), $destPrefix)) {
                continue;
            }

            yield $file;
        }
    }

    /**
     * Count how many files we're about to process.
     *
     * Reuses the same generator so the count can never disagree with what
     * actually gets processed. The $_ just means "we don't care about the
     * value, only that there was one".
     */
    private function count(string $outputExt): int
    {
        $n = 0;

        foreach ($this->files($outputExt) as $_) {
            $n++;
        }

        return $n;
    }

    /**
     * Work out where a given source file's output should be written.
     *
     * Two jobs:
     *   1. If a destination folder was set, rebuild the same sub-path inside
     *      it — uploads/2024/cat.jpg becomes webp/2024/cat.webp.
     *   2. Swap the file extension for the encoder's one.
     */
    private function destinationFor(string $source, string $ext): string
    {
        $path = $this->destination !== null
            // substr() strips the source root off the front, leaving the
            // relative part ("2024/cat.jpg"), which we hang off the new root.
            ? $this->destination . DIRECTORY_SEPARATOR
            . ltrim(substr($source, strlen($this->source)), '/\\')
            : $source;

        // Find the last dot, and the last directory separator.
        $dot   = strrpos($path, '.');
        $slash = strrpos($path, DIRECTORY_SEPARATOR);

        // Only treat that dot as an extension if it comes AFTER the last
        // slash. Otherwise a path like "my.photos/holiday" would have its
        // folder name mangled instead of getting an extension added.
        if ($dot !== false && ($slash === false || $dot > $slash)) {
            return substr($path, 0, $dot) . '.' . $ext;
        }

        // No extension at all — just append one.
        return $path . '.' . $ext;
    }
}
