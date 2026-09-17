<?php

declare(strict_types=1);

namespace MissCache\Tests;

use MissCache\Plugins\PhpThumbPlugin;
use MissCache\Util\CachePurger;
use MissCache\Util\CacheRequest;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class PhpThumbPluginTest extends TestCase
{
    private const JPEG = "\xFF\xD8\xFF\xE0forged-thumbnail-bytes";

    private string $tmp;
    private string $errorLog;

    protected function setUp(): void
    {
        $this->tmp = sys_get_temp_dir() . '/misscache_test_' . getmypid() . '_' . uniqid();
        mkdir($this->tmp, 0775, true);
        $this->errorLog = (string) ini_set('error_log', $this->tmp . '/error.log');   // the plugin's one log line, captured
    }

    protected function tearDown(): void
    {
        ini_set('error_log', $this->errorLog);
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

    /** A request whose source is $this->tmp/img_upload/123/photo.jpg and whose artifact goes to $target. */
    private function request(string $target, bool $sourceExists): CacheRequest
    {
        $srcDir = $this->tmp . '/img_upload/123';
        mkdir($srcDir, 0775, true);
        if ($sourceExists) {
            file_put_contents($srcDir . '/photo.jpg', 'source');
        }
        return new CacheRequest('pT', '123', 'photo.jpg', 'w=10', 'jpg', $target, 0775, 'img_upload', $srcDir . '/photo.jpg');
    }

    /** A plugin whose "HTTP" answers every request with the given status and body. */
    private static function plugin(int $code, string|false $body): PhpThumbPlugin
    {
        return new PhpThumbPlugin('https://example.org/img.php', 'pT', static fn (string $url) => [$code, $body]);
    }

    public function testGetRoutePrefixDefaultsToPt(): void
    {
        self::assertSame('pT', (new PhpThumbPlugin('https://example.org/img.php'))->getRoutePrefix());
    }

    /** The fallback is the shipped blank of the requested output type; jpeg shares the jpg asset. */
    public function testFallbackIsTheBlankOfTheOutputType(): void
    {
        $plugin = new PhpThumbPlugin('https://example.org/img.php');
        foreach (['jpg', 'jpeg', 'png', 'gif', 'webp', 'avif', 'bmp', 'ico'] as $ext) {
            $req   = new CacheRequest('pT', '123', 'photo.jpg', 'w=10', $ext, "/x/photo.jpg!w=10.$ext", 0775, 'img_upload', null);
            $asset = \dirname(__DIR__) . '/assets/blank.' . ($ext === 'jpeg' ? 'jpg' : $ext);
            self::assertStringEqualsFile($asset, $plugin->fallback($req), "blank for .$ext");
        }
        $req = new CacheRequest('pT', '123', 'photo.jpg', 'w=10', 'svg', '/x/photo.jpg!w=10.svg', 0775, 'img_upload', null);
        self::assertNull($plugin->fallback($req), 'no blank for a type we never emit');
    }

    /**
     * An upload replacing the source while phpThumb renders it has purged the cache already -
     * storing the image of the old file now would bring it back for good. It is still served.
     */
    public function testSourceReplacedDuringTheRenderIsServedButNotStored(): void
    {
        $target = $this->tmp . '/img_upload/mC/pT/123/photo.jpg!w=10.jpg';
        $req    = $this->request($target, sourceExists: true);
        $source = $req->sourceFsPath;
        $plugin = new PhpThumbPlugin('https://example.org/img.php', 'pT', static function (string $url) use ($source): array {
            // the way Files::uploadFile() replaces it: a new file renamed over the old one
            file_put_contents("$source.new", 'the new photo');
            rename("$source.new", $source);
            return [200, self::JPEG];
        });

        self::assertSame(self::JPEG, $plugin->generate($req));
        self::assertFileDoesNotExist($target);
    }

    public function testForgedArtifactIsStoredAndReturned(): void
    {
        $target = $this->tmp . '/img_upload/mC/pT/123/photo.jpg!w=10.jpg';
        $req    = $this->request($target, sourceExists: true);
        $asked  = null;
        $plugin = new PhpThumbPlugin('https://example.org/img.php', 'pT', static function (string $url) use (&$asked): array {
            $asked = $url;
            return [200, self::JPEG];
        });

        $bytes = $plugin->generate($req);

        self::assertSame('https://example.org/img.php?src=/img_upload/123/photo.jpg&w=10', $asked);
        self::assertSame(self::JPEG, $bytes);
        self::assertStringEqualsFile($target, self::JPEG);
    }

    /**
     * The purge reaps the 1×1 blanks an earlier version stored on failure - by
     * content, since nothing else tells them from a real artifact - and leaves
     * real artifacts alone whatever their age.
     */
    public function testPurgeReapsStoredBlanksAndKeepsArtifacts(): void
    {
        $cacheDir = $this->tmp . '/img_upload/mC/pT/123';
        mkdir($cacheDir, 0775, true);
        copy(\dirname(__DIR__) . '/assets/blank.jpg', "$cacheDir/gone.jpg!w=10.jpg");
        copy(\dirname(__DIR__) . '/assets/blank.png', "$cacheDir/gone.jpg!w=10!f=png.png");
        file_put_contents("$cacheDir/photo.jpg!w=10.jpg", self::JPEG);
        file_put_contents("$cacheDir/same-size.jpg!w=10.jpg", str_repeat('x', filesize(\dirname(__DIR__) . '/assets/blank.jpg')));

        $stats = (new CachePurger($this->tmp . '/img_upload/mC/pT'))->purge((new PhpThumbPlugin('https://example.org/img.php'))->getPurgeOptions());

        self::assertSame(2, $stats['deleted_stale']);
        self::assertFileDoesNotExist("$cacheDir/gone.jpg!w=10.jpg");
        self::assertFileDoesNotExist("$cacheDir/gone.jpg!w=10!f=png.png");
        self::assertFileExists("$cacheDir/photo.jpg!w=10.jpg");
        self::assertFileExists("$cacheDir/same-size.jpg!w=10.jpg", 'size alone must not condemn a file');
    }

    /**
     * A source PHP cannot see - deleted, not uploaded yet, or a filesystem refusing
     * us for a moment - yields null without a round-trip, and nothing on disk. A
     * stored blank used to outlive the failure: the purge is keyed on recency, so a
     * blank on a live page was refreshed by every hit and never reclaimed.
     */
    public function testUnreadableSourceReturnsNullWithoutHttpAndStoresNothing(): void
    {
        $cacheDir = $this->tmp . '/img_upload/mC/pT/123';
        $req      = $this->request($cacheDir . '/photo.jpg!w=10.jpg', sourceExists: false);
        $plugin   = new PhpThumbPlugin('https://example.org/img.php', 'pT', static function (string $url): array {
            self::fail("no HTTP request expected for an unreadable source, got $url");
        });

        self::assertNull($plugin->generate($req));
        self::assertDirectoryDoesNotExist($cacheDir);
    }

    #[DataProvider('failedFetches')]
    public function testFailedFetchReturnsNullAndStoresNothing(int $code, string|false $body, bool $logged): void
    {
        $cacheDir = $this->tmp . '/img_upload/mC/pT/123';
        $req      = $this->request($cacheDir . '/photo.jpg!w=10.jpg', sourceExists: true);

        self::assertNull(self::plugin($code, $body)->generate($req));
        self::assertDirectoryDoesNotExist($cacheDir);
        // An unusable image is routine and silent; an unreachable entry point is a
        // setup problem or an outage, and this line is the operator's only trace of it.
        self::assertSame($logged, str_contains((string) @file_get_contents($this->tmp . '/error.log'), 'entry point unreachable'));
    }

    /** @return array<string, array{0:int,1:string|false,2:bool}> */
    public static function failedFetches(): array
    {
        return [
            'failure redirect from the entry point' => [302, '', false],
            'server error with a body'              => [500, '<html>error</html>', false],
            'entry point unreachable'               => [0, false, true],
            'empty 200 body'                        => [200, '', false],
        ];
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
        $cacheDir = $this->tmp . '/img_upload/mC/pT/123';
        mkdir($cacheDir, 0775, true);
        $target = $cacheDir . '/' . str_repeat('a', 300) . '.jpg';   // unstorable anywhere
        $req    = $this->request($target, sourceExists: true);

        $bytes = self::plugin(200, self::JPEG)->generate($req);

        self::assertSame(self::JPEG, $bytes, 'a cache that cannot store must still deliver');
        self::assertFileDoesNotExist($target);
        self::assertSame([], glob($cacheDir . '/*.tmp') ?: [], 'a failed store leaves no temp litter');
    }

    /**
     * A name the filesystem accepts must actually be stored.
     *
     * The temp file used to be "<target>.tmp.<hex>", adding 21 bytes to a name that
     * may already be at NAME_MAX: a 235..255-byte artifact then failed to write with
     * ENAMETOOLONG even though the target itself would have fit. The temp name is now
     * short and independent of the target, so the whole budget is available.
     */
    public function testNameNearTheFilesystemLimitIsStored(): void
    {
        $cacheDir = $this->tmp . '/img_upload/mC/pT/123';
        mkdir($cacheDir, 0775, true);
        $name   = str_repeat('a', 250) . '.jpg';   // 254 bytes: fits, old temp name did not
        $target = $cacheDir . '/' . $name;
        self::assertLessThanOrEqual(255, \strlen($name), 'test premise: the name itself is storable');
        $req = $this->request($target, sourceExists: true);

        $bytes = self::plugin(200, self::JPEG)->generate($req);

        self::assertSame(self::JPEG, $bytes);
        self::assertFileExists($target, 'a name within NAME_MAX must reach the disk');
        self::assertStringEqualsFile($target, self::JPEG);
    }
}
