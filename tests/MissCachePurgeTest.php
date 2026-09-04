<?php

declare(strict_types=1);

namespace MissCache\Tests;

use MissCache\MissCache;
use MissCache\Plugins\PhpThumbPlugin;
use MissCache\Util\CacheRequest;
use MissCache\Util\PluginInterface;
use PHPUnit\Framework\TestCase;

/**
 * MissCache::purge() walks per plugin: one CachePurger per route-prefix directory
 * under the cache root, never the root itself.
 *
 * That is safe by construction — parseRequest() rejects a cache path with no
 * segment after the route prefix, so no artifact ever lands directly in the root —
 * and it makes the root the one place in the tree a purge can never reach: the
 * natural home for the .htaccess that routes misses to the dispatcher.
 */
final class MissCachePurgeTest extends TestCase
{
    private string $base;

    protected function setUp(): void
    {
        $this->base = sys_get_temp_dir() . '/misscache_mcpurge_' . getmypid() . '_' . uniqid();
        mkdir($this->base . '/mC', 0775, true);
    }

    protected function tearDown(): void
    {
        if (!is_dir($this->base)) {
            return;
        }
        $it = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($this->base, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($it as $f) {
            $f->isDir() ? @rmdir($f->getPathname()) : @unlink($f->getPathname());
        }
        @rmdir($this->base);
    }

    /** @param array<int,PluginInterface> $plugins */
    private function mc(array $plugins = []): MissCache
    {
        return new MissCache(
            'https://example.org/img_upload',
            $this->base,
            'mC',
            0775,
            $plugins ?: [new PhpThumbPlugin('https://example.org/img.php')]
        );
    }

    /** Write $rel under the cache root and age its atime+mtime by $ageSeconds. */
    private function makeFile(string $rel, int $ageSeconds = 0, int $bytes = 16): string
    {
        $path = $this->base . '/mC/' . $rel;
        @mkdir(\dirname($path), 0775, true);
        file_put_contents($path, str_repeat('x', $bytes));
        $t = time() - $ageSeconds;
        touch($path, $t, $t);
        return $path;
    }

    public function testPurgeNeverTouchesTheCacheRootItself(): void
    {
        $htaccess = $this->makeFile('.htaccess', 86400 * 400);   // written once at setup, ancient
        $tag      = $this->makeFile('CACHEDIR.TAG', 86400 * 400);
        $readme   = $this->makeFile('README', 86400 * 400);
        $artifact = $this->makeFile('pT/123/photo.jpg!w=1.jpg', 86400 * 40);

        $s = $this->mc()->purge(['maxAge' => 86400 * 30, 'maxBytes' => 1]);

        self::assertFileExists($htaccess, 'the rewrite rule that routes misses here must survive');
        self::assertFileExists($tag);
        self::assertFileExists($readme);
        self::assertFileDoesNotExist($artifact, 'artifacts inside the plugin dir are still evicted');
        self::assertSame(1, $s['scanned'], 'only the plugin subtree is walked');
    }

    public function testRetiredPluginTreeIsPurgedWithCallerDefaults(): void
    {
        // No plugin is registered for "zZ" (one that was removed). Its tree is dead —
        // handleRequest() 404s on an unregistered prefix — so it must still expire,
        // otherwise it becomes immortal junk.
        $orphan = $this->makeFile('zZ/1/old.jpg!w=1.jpg', 86400 * 40);

        $s = $this->mc()->purge(['maxAge' => 86400 * 30]);

        self::assertFileDoesNotExist($orphan);
        self::assertSame(1, $s['deleted_age']);
    }

    public function testPluginPurgeOptionsOverrideCallerDefaults(): void
    {
        $short = $this->makeFile('sH/1/a.jpg!w=1.jpg', 86400 * 10);  // 10 days: over the plugin's own TTL
        $long  = $this->makeFile('pT/1/a.jpg!w=1.jpg', 86400 * 10);  // 10 days: under the caller's TTL

        $s = $this->mc([
            new PhpThumbPlugin('https://example.org/img.php'),
            new ShortTtlPlugin(),
        ])->purge(['maxAge' => 86400 * 30]);

        self::assertFileDoesNotExist($short, "the plugin's own 7-day TTL wins for its own subtree");
        self::assertFileExists($long, "the caller's 30-day default still applies to pT");
        self::assertSame(1, $s['deleted_age']);
        self::assertSame(2, $s['scanned'], 'stats are summed across plugins');
    }

    public function testStatsAreSummedAcrossPluginDirectories(): void
    {
        $this->makeFile('pT/1/a.jpg!w=1.jpg', 86400 * 40);
        $this->makeFile('zZ/1/b.jpg!w=1.jpg', 86400 * 40);
        $this->makeFile('pT/1/fresh.jpg!w=1.jpg', 0, 64);

        $s = $this->mc()->purge(['maxAge' => 86400 * 30]);

        self::assertSame(3, $s['scanned']);
        self::assertSame(2, $s['deleted_age']);
        self::assertGreaterThan(0, $s['total_after']);
    }

    public function testMissingCacheRootReturnsZeroStats(): void
    {
        rmdir($this->base . '/mC');

        $s = $this->mc()->purge();

        self::assertSame(0, $s['scanned']);
        self::assertSame(0, $s['deleted_age']);
    }
}

/** Test double: a plugin that keeps its artifacts for a week instead of the caller's default. */
final class ShortTtlPlugin implements PluginInterface
{
    public function getRoutePrefix(): string
    {
        return 'sH';
    }

    public function generate(CacheRequest $req): bool
    {
        return false;
    }

    public function getPurgeOptions(): array
    {
        return ['maxAge' => 86400 * 7];
    }
}
