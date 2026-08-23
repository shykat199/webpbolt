<?php

declare(strict_types=1);

namespace Shykat\WebpBolt;

/**
 * A simple report card for one batch run.
 *
 * BatchProcessor fills this in as it works, and hands it back to you when
 * it finishes. It holds no logic of its own — it just counts things and
 * knows how to present those counts nicely.
 *
 * Example:
 *   $result = (new BatchProcessor())->source('uploads')->run();
 *   echo $result->summary();
 *   // "412 converted, 88 skipped, 2 failed — 31.4MB saved (36.2%) in 47.9s"
 */
class BatchResult
{
    /** How many files we successfully wrote a new image for. */
    public int $converted = 0;

    /**
     * How many files we deliberately did NOT convert.
     * Two reasons: the output already existed and was up to date, or the
     * converted version came out bigger than the original.
     */
    public int $skipped = 0;

    /** Total size of the original files we converted, in bytes. */
    public int $bytesIn = 0;

    /** Total size of the new files we produced, in bytes. */
    public int $bytesOut = 0;

    /**
     * Files that threw an error, and what the error was.
     * The batch keeps going when one file fails — the failure is recorded
     * here instead of stopping everything.
     *
     * @var array<string,string> source file path => error message
     */
    public array $failed = [];

    /** How long the whole run took, in seconds. */
    public float $seconds = 0.0;

    /**
     * How many bytes the conversion saved.
     *
     * Clamped at zero: if you somehow ended up with bigger files overall,
     * "saved -5MB" reads badly, so we report 0 instead.
     */
    public function bytesSaved(): int
    {
        return max(0, $this->bytesIn - $this->bytesOut);
    }

    /**
     * The saving as a percentage of the original size.
     *
     * Guards against divide-by-zero, which happens when nothing was
     * converted (empty folder, or everything was skipped).
     */
    public function percentSaved(): float
    {
        return $this->bytesIn > 0
            ? round($this->bytesSaved() / $this->bytesIn * 100, 1)
            : 0.0;
    }

    /** Every file we looked at, whatever happened to it. */
    public function total(): int
    {
        return $this->converted + $this->skipped + count($this->failed);
    }

    /**
     * One line you can echo straight to a terminal or a log.
     */
    public function summary(): string
    {
        return sprintf(
            '%d converted, %d skipped, %d failed — %s saved (%.1f%%) in %.1fs',
            $this->converted,
            $this->skipped,
            count($this->failed),
            $this->humanBytes($this->bytesSaved()),
            $this->percentSaved(),
            $this->seconds
        );
    }

    /**
     * Turn a raw byte count into something readable.
     * 32948124 becomes "31.4MB".
     *
     * Divides by 1024 repeatedly, stepping up through the unit list each
     * time, until the number is small enough to read — or until we run out
     * of units (that's the second condition in the while).
     */
    private function humanBytes(int $bytes): string
    {
        $units = ['B', 'KB', 'MB', 'GB'];
        $i = 0;
        $n = (float) $bytes;

        while ($n >= 1024 && $i < count($units) - 1) {
            $n /= 1024;
            $i++;
        }

        return round($n, 1) . $units[$i];
    }
}