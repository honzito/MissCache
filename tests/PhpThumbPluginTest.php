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

        self::assertTrue($plugin->generate($req));
        self::assertFileExists($target);

        $asset = \dirname(__DIR__) . '/assets/blank.jpg';
        self::assertFileExists($asset);
        self::assertStringEqualsFile($target, (string) file_get_contents($asset));
    }

    /** The placeholder type follows the requested output extension (jpeg -> jpg asset). */
    public function testPlaceholderMatchesOutputExtension(): void
    {
        foreach (['png', 'gif', 'webp', 'avif'] as $ext) {
            $srcDir = $this->tmp . "/img_upload/$ext";
            mkdir($srcDir, 0775, true);
            $target = $this->tmp . "/img_upload/mC/pT/$ext/gone.png!w=10.$ext";
            $req = new CacheRequest(
                'pT', $ext, 'gone.png', 'w=10', $ext, $target, 0775, 'img_upload', $srcDir . '/gone.png'
            );
            $plugin = new PhpThumbPlugin('http://127.0.0.1:1/never');

            self::assertTrue($plugin->generate($req), "generate() for .$ext");
            self::assertStringEqualsFile(
                $target,
                (string) file_get_contents(\dirname(__DIR__) . "/assets/blank.$ext"),
                "placeholder bytes for .$ext"
            );
        }
    }
}
