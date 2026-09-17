<?php

declare(strict_types=1);

namespace MissCache\Tests;

use MissCache\MissCache;
use MissCache\Plugins\PhpThumbPlugin;
use MissCache\Util\CacheRequest;
use MissCache\Util\PluginInterface;
use PHPUnit\Framework\TestCase;

final class MissCacheTest extends TestCase
{
    private const JPEG = "\xFF\xD8\xFF\xE0forged-thumbnail-bytes";

    private string $tmp;

    protected function setUp(): void
    {
        $this->tmp = sys_get_temp_dir() . '/misscache_test_' . getmypid() . '_' . uniqid();
        mkdir($this->tmp, 0775, true);
    }

    protected function tearDown(): void
    {
        $it = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($this->tmp, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($it as $f) {
            $f->isDir() ? @rmdir($f->getPathname()) : @unlink($f->getPathname());
        }
        @rmdir($this->tmp);
    }

    private function mc(): MissCache
    {
        return new MissCache(
            'https://example.org/img_upload',
            '/srv/example/img_upload',
            'mC',
            0775,
            [new PhpThumbPlugin('https://example.org/img.php')]
        );
    }

    public function testGetCachedUrlJpg(): void
    {
        // The base ("img_upload") is stripped from the source path, so it is not repeated.
        self::assertSame(
            'https://example.org/img_upload/mC/pT/123/photo.jpg!w=150!h=150!zc=1.jpg',
            $this->mc()->getCachedUrl('pT', 'img_upload/123/photo.jpg?w=150&h=150&zc=1')
        );
    }

    public function testGetCachedUrlUsesOutputFormatExtension(): void
    {
        $url = $this->mc()->getCachedUrl('pT', 'img_upload/123/photo.jpg?w=150&f=avif');
        self::assertStringEndsWith('.avif', $url);
    }

    public function testGetCachedUrlUnknownPluginThrows(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->mc()->getCachedUrl('nope', 'img_upload/123/photo.jpg?w=150');
    }

    public function testParseRequestRoundtrip(): void
    {
        $mc  = $this->mc();
        $url = $mc->getCachedUrl('pT', 'img_upload/123/photo.jpg?w=150&h=150&zc=1');

        $req = $mc->parseRequest($url);
        self::assertNotNull($req);
        self::assertSame('pT', $req->routePrefix);
        self::assertSame('123', $req->srcDir);              // base ("img_upload") stripped
        self::assertSame('photo.jpg', $req->srcName);
        self::assertSame('w=150&h=150&zc=1', $req->params);
        self::assertSame('jpg', $req->outExt);
        self::assertSame(
            '/srv/example/img_upload/mC/pT/123/photo.jpg!w=150!h=150!zc=1.jpg',
            $req->filesystemPath
        );
        // Source path on disk mirrors the cache under basePath (base not repeated).
        self::assertSame('/srv/example/img_upload/123/photo.jpg', $req->sourceFsPath);
        // src is rebuilt with the base re-added — exactly the original backend path.
        self::assertSame('src=/img_upload/123/photo.jpg&w=150&h=150&zc=1', $req->toRawQueryString(true));
    }

    public function testParseRequestReturnsNullForNonCacheUrl(): void
    {
        self::assertNull($this->mc()->parseRequest('/some/other/path.jpg'));
        self::assertNull($this->mc()->parseRequest('/img_upload/123/photo.jpg')); // not under mC/
    }

    public function testParseRequestRejectsPathTraversal(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->mc()->parseRequest('/img_upload/mC/pT/../../../etc/passwd.jpg');
    }

    public function testParseRequestRejectsEncodedSlashInName(): void
    {
        // Decoded source name "a/b" must be rejected (it is not a pure basename).
        $this->expectException(\RuntimeException::class);
        $this->mc()->parseRequest('/img_upload/mC/pT/img_upload/123/a~2Fb!w~3D1.jpg');
    }

    public function testParseRequestRejectsDangerousExtension(): void
    {
        // A non-asset output extension (.php) must never be written into the public cache.
        $this->expectException(\RuntimeException::class);
        $this->mc()->parseRequest('/img_upload/mC/pT/img_upload/123/shell!w~3D1.php');
    }

    /**
     * The output-extension whitelist must not exceed what a plugin can emit:
     * a hand-crafted .svg/.js/.css URL would otherwise cache image bytes under a
     * mismatched content-type. Only image extensions are allowed.
     *
     * @dataProvider nonImageExtensions
     */
    public function testParseRequestRejectsNonImageExtension(string $ext): void
    {
        $this->expectException(\RuntimeException::class);
        $this->mc()->parseRequest("/img_upload/mC/pT/img_upload/123/logo!w~3D1.$ext");
    }

    /**
     * A control byte in the params only ever comes from a hand-crafted URL (a template
     * cannot put one there), and it would reach the backend query string and the error
     * log as-is - so it is rejected before anything else looks at it.
     */
    public function testParseRequestRejectsControlBytesInParams(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Illegal parameter');
        $this->mc()->parseRequest('/img_upload/mC/pT/123/photo.jpg!w=150!~0A~0DForged~3A~20line.jpg');
    }

    /** @return array<string,array{string}> */
    public static function nonImageExtensions(): array
    {
        return [
            'svg' => ['svg'], 'css' => ['css'], 'js' => ['js'], 'pdf' => ['pdf'],
            // spellings getCachedUrl() never emits: each would be a second storable path for the same artifact
            'JPG' => ['JPG'], 'jpeg' => ['jpeg'],
        ];
    }

    /**
     * When nothing can be forged the dispatcher answers the plugin's fallback,
     * briefly cacheable, and leaves NOTHING on disk - so a source that is unreadable
     * for a moment (a filesystem refusing PHP) shows up again as soon as it can be
     * read, instead of being replaced by a 1×1 for good.
     */
    public function testMissWithUnreadableSourceServesFallbackAndStoresNothing(): void
    {
        mkdir($this->tmp . '/123', 0775, true);   // source dir exists, photo.jpg does not
        $plugin = new PhpThumbPlugin('https://example.org/img.php', 'pT', static function (string $url): array {
            self::fail("no HTTP request expected for an unreadable source, got $url");
        });
        $mc = new MissCache('https://example.org/img_upload', $this->tmp, 'mC', 0775, [$plugin]);

        ob_start();
        try {
            $handled = $mc->handleRequest('/img_upload/mC/pT/123/photo.jpg!w=10!f=png.png');
        } finally {
            $out = ob_get_clean();
        }

        self::assertTrue($handled);
        self::assertStringEqualsFile(\dirname(__DIR__) . '/assets/blank.png', $out);
        self::assertDirectoryDoesNotExist($this->tmp . '/mC');
    }

    /** A plugin with nothing to fall back on gets an error status, never an empty 200 "image". */
    public function testMissWithoutFallbackAnswers500(): void
    {
        $plugin = new class implements PluginInterface {
            public function getRoutePrefix(): string { return 'pT'; }
            public function generate(CacheRequest $req): ?string { return null; }
            public function fallback(CacheRequest $req): ?string { return ''; }
            public function getPurgeOptions(): array { return []; }
        };
        $mc = new MissCache('https://example.org/img_upload', $this->tmp, 'mC', 0775, [$plugin]);

        ob_start();
        try {
            $handled = $mc->handleRequest('/img_upload/mC/pT/123/photo.jpg!w=10.jpg');
        } finally {
            $out = ob_get_clean();
        }

        self::assertTrue($handled);
        self::assertSame('', $out);
        self::assertSame(500, http_response_code());
        self::assertDirectoryDoesNotExist($this->tmp . '/mC');
    }

    /**
     * The output of handleRequest($path) on a cache in $this->tmp whose phpThumb entry point
     * answers $fetch - with the source file $this->tmp/$source.
     *
     * @param \Closure(string): array{0:int,1:string|false} $fetch
     */
    private function dispatch(string $path, \Closure $fetch, string $source = '123/photo.jpg'): string
    {
        @mkdir(\dirname("$this->tmp/$source"), 0775, true);
        file_put_contents("$this->tmp/$source", 'source');
        $mc = new MissCache('https://example.org/img_upload', $this->tmp, 'mC', 0775, [new PhpThumbPlugin('https://example.org/img.php', 'pT', $fetch)]);
        ob_start();
        try {
            self::assertTrue($mc->handleRequest($path));
        } finally {
            $out = (string) ob_get_clean();
        }
        return $out;
    }

    public function testForgedArtifactIsStoredAndServed(): void
    {
        $asked = null;
        $out   = $this->dispatch('/img_upload/mC/pT/123/photo.jpg!w=10.jpg', static function (string $url) use (&$asked): array {
            $asked = $url;
            return [200, self::JPEG];
        });

        self::assertSame('https://example.org/img.php?src=/img_upload/123/photo.jpg&w=10', $asked);
        self::assertSame(self::JPEG, $out);
        self::assertStringEqualsFile($this->tmp . '/mC/pT/123/photo.jpg!w=10.jpg', self::JPEG);
        self::assertSame([], glob($this->tmp . '/mC/pT/123/*.tmp') ?: [], 'no temp file left behind');
    }

    /**
     * An upload replacing the source while the artifact is generated has purged the cache
     * already - storing the artifact of the old file now would bring it back for good. It
     * is still served.
     */
    public function testSourceReplacedDuringGenerationIsServedButNotStored(): void
    {
        $source = $this->tmp . '/123/photo.jpg';
        $out    = $this->dispatch('/img_upload/mC/pT/123/photo.jpg!w=10.jpg', static function (string $url) use ($source): array {
            // the way Files::uploadFile() replaces it: a new file renamed over the old one
            file_put_contents("$source.new", 'the new photo');
            rename("$source.new", $source);
            return [200, self::JPEG];
        });

        self::assertSame(self::JPEG, $out);
        self::assertFileDoesNotExist($this->tmp . '/mC/pT/123/photo.jpg!w=10.jpg');
    }

    /** the directories on the way are group-writable whatever the umask: another PHP user stores and purges there too */
    public function testDirectoriesMadeForAnArtifactGetTheirModeWhateverTheUmask(): void
    {
        $umask = umask(0o022);
        try {
            $this->dispatch('/img_upload/mC/pT/123/sub/photo.jpg!w=10.jpg', static fn (string $url): array => [200, self::JPEG], '123/sub/photo.jpg');
        } finally {
            umask($umask);
        }

        self::assertStringEqualsFile($this->tmp . '/mC/pT/123/sub/photo.jpg!w=10.jpg', self::JPEG);
        foreach (['/mC', '/mC/pT', '/mC/pT/123', '/mC/pT/123/sub'] as $dir) {
            self::assertSame(0o775, fileperms($this->tmp . $dir) & 0o777, $dir);
        }
    }

    /**
     * A cache that cannot STORE must still DELIVER: a failed store (full disk, read-only
     * mount, a name over NAME_MAX, wrong permissions) costs a repeated miss, never a broken
     * image - that is how images on biom.cz vanished once their names grew. Forced here by
     * a directory in the artifact's place, which no rename replaces whatever the uid.
     */
    public function testArtifactIsServedEvenWhenItCannotBeStored(): void
    {
        $target = $this->tmp . '/mC/pT/123/photo.jpg!w=10.jpg';
        mkdir($target, 0775, true);

        $out = $this->dispatch('/img_upload/mC/pT/123/photo.jpg!w=10.jpg', static fn (string $url): array => [200, self::JPEG]);

        self::assertSame(self::JPEG, $out, 'a cache that cannot store must still deliver');
        self::assertDirectoryExists($target);
        self::assertSame([], glob($this->tmp . '/mC/pT/123/*.tmp') ?: [], 'a failed store leaves no temp litter');
    }

    /**
     * A name the filesystem accepts must actually be stored: the temp file used to be
     * "<target>.tmp.<hex>", 21 bytes over a name already near NAME_MAX, so a 235..255-byte
     * artifact failed to write although the target itself would have fit.
     */
    public function testNameNearTheFilesystemLimitIsStored(): void
    {
        $name = str_repeat('a', 241) . '.jpg';
        $path = (string) parse_url($this->mc()->getCachedUrl('pT', "img_upload/123/$name?w=10"), PHP_URL_PATH);
        self::assertSame(254, \strlen(basename($path)), 'test premise: one path component near NAME_MAX');

        $out = $this->dispatch($path, static fn (string $url): array => [200, self::JPEG], "123/$name");

        self::assertSame(self::JPEG, $out);
        self::assertStringEqualsFile($this->tmp . '/mC/pT/123/' . basename($path), self::JPEG);
    }

    /**
     * The fallback is cacheable only briefly; an unstored real artifact as long as a stored
     * one. Expires matches max-age: without it mod_expires appends its own week-long
     * Cache-Control to the response.
     */
    public function testUnstoredHeaders(): void
    {
        $now      = 1_700_000_000;
        $fallback = self::invokeStatic('unstoredHeaders', ['png', 91, 60, $now]);
        self::assertSame(
            [
                'Content-Type'           => 'image/png',
                'Content-Length'         => '91',
                'Cache-Control'          => 'public, max-age=60',
                'Expires'                => gmdate('D, d M Y H:i:s', $now + 60) . ' GMT',
                'X-Content-Type-Options' => 'nosniff',
            ],
            $fallback
        );

        $artifact = self::invokeStatic('unstoredHeaders', ['jpg', 5043, 604800, $now]);
        self::assertSame('public, max-age=604800', $artifact['Cache-Control']);
        self::assertSame(gmdate('D, d M Y H:i:s', $now + 604800) . ' GMT', $artifact['Expires']);
        self::assertArrayNotHasKey('Last-Modified', $artifact, 'no file behind it, nothing for a validator to line up with');
    }

    public function testCacheHeadersShape(): void
    {
        $mtime   = 1_700_000_000;
        $now     = $mtime + 3600;
        $headers = self::invokeStatic('cacheHeaders', ['webp', $mtime, 5043, $now]);

        self::assertSame('image/webp', $headers['Content-Type']);
        self::assertSame('5043', $headers['Content-Length']);
        self::assertSame(gmdate('D, d M Y H:i:s', $mtime) . ' GMT', $headers['Last-Modified']);
        self::assertSame('public, max-age=604800', $headers['Cache-Control']);
        self::assertSame(gmdate('D, d M Y H:i:s', $now + 604800) . ' GMT', $headers['Expires']);
        // No ETag: the static server issues its own; a PHP one would only fail to match.
        self::assertArrayNotHasKey('ETag', $headers);
    }

    public function testClientCacheFreshIfModifiedSince(): void
    {
        $mtime = 1_700_000_000;

        self::assertTrue(self::isFresh($mtime, [
            'HTTP_IF_MODIFIED_SINCE' => gmdate('D, d M Y H:i:s', $mtime) . ' GMT',
        ]));
        self::assertTrue(self::isFresh($mtime, [
            'HTTP_IF_MODIFIED_SINCE' => gmdate('D, d M Y H:i:s', $mtime + 5) . ' GMT',
        ]));
        self::assertFalse(self::isFresh($mtime, [
            'HTTP_IF_MODIFIED_SINCE' => gmdate('D, d M Y H:i:s', $mtime - 5) . ' GMT',
        ]));
        self::assertFalse(self::isFresh($mtime, ['HTTP_IF_MODIFIED_SINCE' => 'not-a-date']));
        self::assertFalse(self::isFresh($mtime, [])); // no conditional header
    }

    /** @param array<string,mixed> $server */
    private static function isFresh(int $mtime, array $server): bool
    {
        return (bool) self::invokeStatic('isClientCacheFresh', [$mtime, $server]);
    }

    /** @param array<int,mixed> $args */
    private static function invokeStatic(string $method, array $args): mixed
    {
        $m = new \ReflectionMethod(MissCache::class, $method);
        $m->setAccessible(true);
        return $m->invoke(null, ...$args);
    }

    /** a base url spelled with "//" inside is stripped as configured - collapsing it first moved every cache url */
    public function testBaseWithDoubleSlashIsStrippedAsWritten(): void
    {
        $mc  = new MissCache('https://x.cz/web//img_upload', '/srv/web/img_upload', 'mC', 0775, [new PhpThumbPlugin('https://x.cz/img.php')]);
        $url = $mc->getCachedUrl('pT', 'web//img_upload/123//x.jpg?w=1');

        self::assertSame('https://x.cz/web//img_upload/mC/pT/123/x.jpg!w=1.jpg', $url);
        self::assertSame('/srv/web/img_upload/123/x.jpg', $mc->parseRequest((string) parse_url($url, PHP_URL_PATH))?->sourceFsPath);
    }

    /** a thumbnail is no source: "mC/pT/mC/pT/..." would nest without end, out of purgeSource()'s reach */
    public function testParseRequestRejectsASourceInsideTheCache(): void
    {
        $mc = $this->mc();
        $this->expectException(\RuntimeException::class);
        $mc->parseRequest($mc->getCachedUrl('pT', 'img_upload/mC/pT/123/photo.jpg!w=150.jpg?w=10'));
    }

    public function testParseRequestRejectsPercentEncoding(): void
    {
        // Percent-encoding never appears in a real cache path (tilde-hex uses ~HH).
        $this->expectException(\RuntimeException::class);
        $this->mc()->parseRequest('/img_upload/mC/pT/img_upload/%2e%2e/photo.jpg');
    }

    /**
     * Only the spelling getCachedUrl() emits is accepted: every alias of an artifact would be
     * forged and stored as a copy of its own, which purgeSource() never finds.
     *
     * @return array<string, array{0: string}>
     */
    public static function nonCanonicalPaths(): array
    {
        return [
            'needlessly escaped letter'   => ['/img_upload/mC/pT/123/ph~6Fto.jpg!w=150.jpg'],
            'escaped dot'                 => ['/img_upload/mC/pT/123/photo~2Ejpg!w=150.jpg'],
            'lowercase escape'            => ['/img_upload/mC/pT/123/photo~2ejpg!w=150.jpg'],
            'long escape of ampersand'    => ['/img_upload/mC/pT/123/a~26b.jpg!w=150.jpg'],
            'extension without f='        => ['/img_upload/mC/pT/123/photo.jpg!w=150.png'],
            'current directory segment'   => ['/img_upload/mC/pT/./+5/photo.jpg!w=1.jpg'],
            'empty directory segment'     => ['/img_upload/mC/pT//+5/photo.jpg!w=1.jpg'],
            'empty segment deeper'        => ['/img_upload/mC/pT/123//photo.jpg!w=1.jpg'],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('nonCanonicalPaths')]
    public function testParseRequestRejectsNonCanonicalSpelling(string $path): void
    {
        $this->expectException(\RuntimeException::class);
        $this->mc()->parseRequest($path);
    }

    /** "img_upload//123/./x.jpg" is the same file as "img_upload/123/x.jpg" - one url for both */
    public function testCachedUrlNormalisesTheSourcePath(): void
    {
        $mc = $this->mc();
        self::assertSame($mc->getCachedUrl('pT', 'img_upload/123/x.jpg?w=1'), $mc->getCachedUrl('pT', 'img_upload//123/./x.jpg?w=1'));
        self::assertNotNull($mc->parseRequest($mc->getCachedUrl('pT', 'img_upload//123/./x.jpg?w=1')));
    }
}
