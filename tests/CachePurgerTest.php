<?php

declare(strict_types=1);

namespace MissCache\Tests;

use MissCache\Util\CachePurger;
use PHPUnit\Framework\TestCase;

final class CachePurgerTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/misscache_purge_' . getmypid() . '_' . uniqid();
        mkdir($this->root, 0775, true);
    }

    protected function tearDown(): void
    {
        if (!is_dir($this->root)) {
            return;
        }
        $it = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($this->root, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($it as $f) {
            $f->isDir() ? @rmdir($f->getPathname()) : @unlink($f->getPathname());
        }
        @rmdir($this->root);
    }

    /** Write $bytes to $rel (creating dirs) and set its atime+mtime $ageSeconds in the past. */
    private function makeFile(string $rel, int $ageSeconds = 0, int $bytes = 16): string
    {
        $path = $this->root . '/' . $rel;
        @mkdir(\dirname($path), 0775, true);
        file_put_contents($path, str_repeat('x', $bytes));
        $t = time() - $ageSeconds;
        touch($path, $t, $t); // mtime, atime
        return $path;
    }

    public function testTtlDeletesOldKeepsFresh(): void
    {
        $old   = $this->makeFile('pT/123/old.jpg!w=1.jpg', 86400 * 40); // 40 days
        $fresh = $this->makeFile('pT/123/new.jpg!w=1.jpg', 86400 * 1);  // 1 day

        $s = (new CachePurger($this->root))->purge(['maxAge' => 86400 * 30]);

        self::assertFileDoesNotExist($old);
        self::assertFileExists($fresh);
        self::assertSame(2, $s['scanned']);
        self::assertSame(1, $s['deleted_age']);
    }

    public function testRecencyUsesMaxOfAtimeMtime(): void
    {
        // Old mtime but fresh atime -> max() keeps it alive (LRU where atime is live).
        $path = $this->makeFile('pT/1/a.jpg!w=1.jpg', 0, 16);
        $old  = time() - 86400 * 40;
        $new  = time();
        touch($path, $old, $new); // mtime=40d ago, atime=now

        $s = (new CachePurger($this->root))->purge(['maxAge' => 86400 * 30]);

        self::assertFileExists($path);
        self::assertSame(0, $s['deleted_age']);
    }

    public function testStrayTmpFilesReapedWhenOld(): void
    {
        $oldTmp   = $this->makeFile('pT/1/x.jpg!w=1.jpg.tmp.deadbeef', 7200); // 2 h old
        $freshTmp = $this->makeFile('pT/1/y.jpg!w=1.jpg.tmp.cafe1234', 60);   // 1 min old

        $s = (new CachePurger($this->root))->purge(['maxAge' => 86400 * 30, 'tmpMaxAge' => 3600]);

        self::assertFileDoesNotExist($oldTmp);
        self::assertFileExists($freshTmp);
        self::assertSame(1, $s['deleted_tmp']);
    }

    public function testSizeCapEvictsOldestFirstToLowWatermark(): void
    {
        // Four ~100 KB files (large enough that block-rounding is negligible), ages 4..1 days.
        // Cap 300 KB, watermark 0.5 -> target 150 KB: one file fits, two don't, so evict the
        // three oldest and keep only the newest (f1).
        foreach ([4, 3, 2, 1] as $days) {
            $this->makeFile("pT/1/f{$days}.jpg!w=1.jpg", 86400 * $days, 100 * 1024);
        }
        $s = (new CachePurger($this->root))->purge([
            'maxAge'       => 86400 * 30, // TTL keeps all four
            'maxBytes'     => 300 * 1024, // 300 KB cap
            'lowWatermark' => 0.5,        // -> target 150 KB
        ]);

        self::assertFileExists($this->root . '/pT/1/f1.jpg!w=1.jpg');        // newest survives
        self::assertFileDoesNotExist($this->root . '/pT/1/f4.jpg!w=1.jpg');  // oldest evicted first
        self::assertFileDoesNotExist($this->root . '/pT/1/f3.jpg!w=1.jpg');
        self::assertSame(3, $s['deleted_size']);
        self::assertLessThanOrEqual(150 * 1024, $s['total_after']);
    }

    public function testNoCapWhenUnderBudget(): void
    {
        $this->makeFile('pT/1/a.jpg!w=1.jpg', 0, 1024);
        $s = (new CachePurger($this->root))->purge(['maxBytes' => 1024 * 1024]); // 1 MB cap, well under

        self::assertSame(0, $s['deleted_size']);
        self::assertFileExists($this->root . '/pT/1/a.jpg!w=1.jpg');
    }

    public function testEmptyDirsPruned(): void
    {
        $this->makeFile('pT/empty/gone.jpg!w=1.jpg', 86400 * 40);
        (new CachePurger($this->root))->purge(['maxAge' => 86400 * 30]);

        self::assertDirectoryDoesNotExist($this->root . '/pT/empty'); // emptied -> pruned
    }

    public function testDryRunDeletesNothingButCounts(): void
    {
        $old = $this->makeFile('pT/1/old.jpg!w=1.jpg', 86400 * 40, 32);
        $s   = (new CachePurger($this->root))->purge(['maxAge' => 86400 * 30, 'dryRun' => true]);

        self::assertFileExists($old);              // nothing actually deleted
        self::assertSame(1, $s['deleted_age']);    // but it is reported
        self::assertGreaterThan(0, $s['bytes_freed']);
    }

    public function testMissingCacheRootReturnsZeroStats(): void
    {
        $s = (new CachePurger($this->root . '/does-not-exist'))->purge();
        self::assertSame(0, $s['scanned']);
        self::assertSame(0, $s['deleted_age']);
    }
}
