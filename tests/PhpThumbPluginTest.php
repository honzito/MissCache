<?php

declare(strict_types=1);

namespace MissCache\Tests;

use MissCache\Plugins\PhpThumbPlugin;
use MissCache\Util\CacheRequest;
use PHPUnit\Framework\TestCase;

final class PhpThumbPluginTest extends TestCase
{
    private string $tmp;

    protected function setUp(): void
    {
        $this->tmp = sys_get_temp_dir() . '/misscache_test_' . getmypid() . '_' . uniqid();
        mkdir($this->tmp, 0775, true);
    }

    protected function tearDown(): void
    {
        // Remove the temp tree (files + dirs), best-effort.
        $it = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($this->tmp, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($it as $f) {
            $f->isDir() ? @rmdir($f->getPathname()) : @unlink($f->getPathname());
        }
        @rmdir($this->tmp);
    }

    public function testGetRoutePrefixDefaultsToPt(): void
    {
        self::assertSame('pT', (new PhpThumbPlugin('https://example.org/img.php'))->getRoutePrefix());
    }

    /**
     * When the source's directory exists but the file does not, the source is
     * definitively missing: the plugin negative-caches the matching 1×1
     * placeholder WITHOUT any HTTP round-trip, so later requests are static.
     */
    public function testMissingSourceWritesPlaceholderWithoutHttp(): void
    {
        $srcDir = $this->tmp . '/img_upload/123';
        mkdir($srcDir, 0775, true);                       // dir exists...
        $sourceFsPath = $srcDir . '/gone.jpg';            // ...but file does not

        $target = $this->tmp . '/img_upload/mC/pT/123/gone.jpg!w=10.jpg';
        $req = new CacheRequest(
            'pT', '123', 'gone.jpg', 'w=10', 'jpg', $target, 0775, 'img_upload', $sourceFsPath
        );

        // Unreachable entry URL proves no HTTP fallback is taken on the fast path.
        $plugin = new PhpThumbPlugin('http://127.0.0.1:1/never');

        $bytes = $plugin->generate($req);
        self::assertFileExists($target);

        $asset = \dirname(__DIR__) . '/assets/blank.jpg';
        self::assertFileExists($asset);
        self::assertStringEqualsFile($target, (string) file_get_contents($asset));
        self::assertSame((string) file_get_contents($asset), $bytes, 'generate() returns the artifact bytes');
    }

    /**
     * A cache that cannot STORE must still DELIVER.
     *
     * generate() returns the forged bytes even when nothing reached the disk, so a
     * failed store (full disk, read-only mount, a name over the filesystem's
     * NAME_MAX, wrong permissions) costs a repeated miss and never a broken image.
     * Before this, the bytes were dropped on the floor and the dispatcher answered
     * 500 forever — which is how images on biom.cz vanished once their names grew.
     *
     * The store is forced to fail with a name past NAME_MAX (255 bytes), the one
     * failure mode reproducible without depending on the uid the tests run as.
     */
    public function testArtifactIsReturnedEvenWhenItCannotBeStored(): void
    {
        $srcDir = $this->tmp . '/img_upload/123';
        mkdir($srcDir, 0775, true);
        $cacheDir = $this->tmp . '/img_upload/mC/pT/123';
        mkdir($cacheDir, 0775, true);

        $target = $cacheDir . '/' . str_repeat('a', 300) . '.jpg';   // unstorable anywhere
        $req    = new CacheRequest(
            'pT', '123', 'gone.jpg', 'w=10', 'jpg', $target, 0775, 'img_upload', $srcDir . '/gone.jpg'
        );
        $plugin = new PhpThumbPlugin('http://127.0.0.1:1/never');

        $bytes = $plugin->generate($req);

        self::assertNotNull($bytes, 'a cache that cannot store must still deliver');
        self::assertStringEqualsFile(\dirname(__DIR__) . '/assets/blank.jpg', $bytes);
        self::assertFileDoesNotExist($target);
        self::assertSame([], glob($cacheDir . '/*.tmp') ?: [], 'a failed store leaves no temp litter');
    }

    /** The placeholder type follows the requested output extension (jpeg -> jpg asset). */
    public function testPlaceholderMatchesOutputExtension(): void
    {
        foreach (['png', 'gif', 'webp', 'avif', 'bmp', 'ico'] as $ext) {
            $srcDir = $this->tmp . "/img_upload/$ext";
            mkdir($srcDir, 0775, true);
            $target = $this->tmp . "/img_upload/mC/pT/$ext/gone.png!w=10.$ext";
            $req = new CacheRequest(
                'pT', $ext, 'gone.png', 'w=10', $ext, $target, 0775, 'img_upload', $srcDir . '/gone.png'
            );
            $plugin = new PhpThumbPlugin('http://127.0.0.1:1/never');

            self::assertNotNull($plugin->generate($req), "generate() for .$ext");
            self::assertStringEqualsFile(
                $target,
                (string) file_get_contents(\dirname(__DIR__) . "/assets/blank.$ext"),
                "placeholder bytes for .$ext"
            );
        }
    }
}
