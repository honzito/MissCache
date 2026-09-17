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

    /** A request for img_upload/123/photo.jpg whose artifact goes to $target - the plugin never looks at the source file. */
    private function request(string $target): CacheRequest
    {
        return new CacheRequest('pT', '123', 'photo.jpg', 'w=10', 'jpg', $target, 0775, 'img_upload', null);
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

    /** @return array<string, array{0: string}> */
    public static function foreignParams(): array
    {
        return [
            'second source'           => ['w=150&src=/etc/other.jpg'],
            'second source, spaced'   => ['w=150& src=/etc/other.jpg'],
            'remote source'           => ['src=http://10.0.0.1/x.jpg&w=150'],
            'image of no source'      => ['new=FFFFFF&w=20&h=20'],
            'debug text'              => ['w=150&phpThumbDebug=9'],
            'forced download'         => ['w=150&down=x.jpg'],
            'hash instead of an image' => ['w=150&md5s='],
        ];
    }

    /** a hand-crafted url must not get another image stored under this source's name */
    #[DataProvider('foreignParams')]
    public function testParametersNoArtifactIsMadeWithAreRefused(string $params): void
    {
        $req    = new CacheRequest('pT', '123', 'photo.jpg', $params, 'jpg', $this->tmp . '/img_upload/mC/pT/123/photo.jpg!x.jpg', 0775, 'img_upload', null);
        $plugin = new PhpThumbPlugin('https://example.org/img.php', 'pT', static function (string $url): array {
            self::fail("no request to the entry point expected, got $url");
        });

        self::assertNull($plugin->generate($req));
    }

    /** the plugin only makes the bytes - MissCache stores them (MissCacheTest) */
    public function testForgedArtifactIsReturnedAndNotWritten(): void
    {
        $target = $this->tmp . '/img_upload/mC/pT/123/photo.jpg!w=10.jpg';
        $req    = $this->request($target);
        $asked  = null;
        $plugin = new PhpThumbPlugin('https://example.org/img.php', 'pT', static function (string $url) use (&$asked): array {
            $asked = $url;
            return [200, self::JPEG];
        });

        $bytes = $plugin->generate($req);

        self::assertSame('https://example.org/img.php?src=/img_upload/123/photo.jpg&w=10', $asked);
        self::assertSame(self::JPEG, $bytes);
        self::assertDirectoryDoesNotExist($this->tmp . '/img_upload/mC');
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

    #[DataProvider('failedFetches')]
    public function testFailedFetchReturnsNull(int $code, string|false $body, bool $logged): void
    {
        $req = $this->request($this->tmp . '/img_upload/mC/pT/123/photo.jpg!w=10.jpg');

        self::assertNull(self::plugin($code, $body)->generate($req));

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
}
