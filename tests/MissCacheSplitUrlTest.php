<?php

declare(strict_types=1);

namespace MissCache\Tests;

use MissCache\MissCache;
use MissCache\Plugins\PhpThumbPlugin;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * End-to-end round trip of the long-name split: getCachedUrl() -> parseRequest().
 *
 * These two are the only pair that has to agree; everything else in MissCache reads
 * the CacheRequest they produce. The split is what makes an over-long name reachable
 * at all — Apache answers 403 on a path component over NAME_MAX before PHP runs.
 */
final class MissCacheSplitUrlTest extends TestCase
{
    private function cache(int $maxSegment = 255): MissCache
    {
        return new MissCache(
            'https://example.org/img_upload',
            '/var/www/example.org/img_upload',
            'mC',
            0775,
            [new PhpThumbPlugin('https://example.org/img.php')],
            $maxSegment,
        );
    }

    /** @return array<string, array{0:string, 1:string}> srcPath, params */
    public static function sourceProvider(): array
    {
        $long = str_repeat('cilem-je-zajistit-aby-biomasa-pro-energetiku-nebyla-pestovana-', 4);

        return [
            'short name, no split'   => ['img_upload/123/photo.jpg', 'w=150&h=150&zc=1'],
            'long name'              => ["img_upload/123/$long.jpg", 'w=680&h=453&zc=1&f=webp'],
            'very long name'         => ['img_upload/123/' . str_repeat('x', 900) . '.jpg', 'w=100&h=100&zc=1'],
            'long name, nested dir'  => ["img_upload/a/b/c/$long.png", 'w=100&h=100&zc=1'],
            'long name, no params'   => ["img_upload/123/$long.jpg", ''],
            'long name, root dir'    => ["img_upload/$long.jpg", 'w=100&h=100&zc=1'],
        ];
    }

    #[DataProvider('sourceProvider')]
    public function testUrlRoundTripsBackToTheSameRequest(string $srcPath, string $params): void
    {
        $mc  = $this->cache();
        $url = $mc->getCachedUrl('pT', $srcPath . ($params === '' ? '' : '?' . $params));

        $req = $mc->parseRequest($url);

        self::assertNotNull($req);
        self::assertSame('pT', $req->routePrefix);
        self::assertSame(basename($srcPath), $req->srcName);
        self::assertSame($params === '' ? null : $params, $req->params);

        // The rebuilt backend query must name the original source again.
        self::assertStringStartsWith('src=/' . $srcPath, $req->toRawQueryString());
    }

    #[DataProvider('sourceProvider')]
    public function testEveryPathSegmentFitsTheFilesystemLimit(string $srcPath, string $params): void
    {
        $url  = $this->cache()->getCachedUrl('pT', $srcPath . ($params === '' ? '' : '?' . $params));
        $path = (string) parse_url($url, PHP_URL_PATH);

        foreach (explode('/', trim($path, '/')) as $segment) {
            self::assertLessThanOrEqual(255, \strlen($segment), "segment over NAME_MAX in: $path");
        }
    }

    /** A short name must produce exactly the URL it always did — no cache is orphaned. */
    public function testShortNameUrlIsUnchanged(): void
    {
        self::assertSame(
            'https://example.org/img_upload/mC/pT/123/photo.jpg!w=150!h=150!zc=1.jpg',
            $this->cache()->getCachedUrl('pT', 'img_upload/123/photo.jpg?w=150&h=150&zc=1'),
        );
    }

    /** The split URL carries the marker at a fixed position and keeps the extension last. */
    public function testSplitUrlShape(): void
    {
        $url = $this->cache()->getCachedUrl('pT', 'img_upload/123/' . str_repeat('y', 400) . '.jpg?w=100');

        self::assertMatchesRegularExpression('~/mC/pT/\+2/123/[^/]+/[^/]+\.jpg$~', $url, "got: $url");
    }

    /** A lower cap (eCryptfs) splits further, and still round-trips. */
    public function testSmallerCapSplitsFurtherAndStillRoundTrips(): void
    {
        $mc  = $this->cache(143);
        $src = 'img_upload/123/' . str_repeat('z', 400) . '.jpg';
        $url = $mc->getCachedUrl('pT', $src . '?w=100');

        foreach (explode('/', trim((string) parse_url($url, PHP_URL_PATH), '/')) as $segment) {
            self::assertLessThanOrEqual(143, \strlen($segment));
        }
        self::assertSame(basename($src), $mc->parseRequest($url)?->srcName);
    }

    /** @return array<string, array{0:string}> */
    public static function malformedProvider(): array
    {
        return [
            'marker announces more segments than exist' => ['/img_upload/mC/pT/+5/123/a/b.jpg'],
            'marker of one'                             => ['/img_upload/mC/pT/+1/123/a.jpg'],
            'empty segment inside the split'            => ['/img_upload/mC/pT/+2/123//a.jpg'],
            'split that was never needed'               => ['/img_upload/mC/pT/+2/123/a/b.jpg'],
            'chunks not cut where we would cut them'    => ['/img_upload/mC/pT/+2/123/' . str_repeat('a', 300) . '/' . str_repeat('b', 100) . '.jpg'],
        ];
    }

    #[DataProvider('malformedProvider')]
    public function testMalformedSplitIsRejected(string $uri): void
    {
        $this->expectException(\RuntimeException::class);
        $this->cache()->parseRequest($uri);
    }

    /**
     * Only the split we would have emitted ourselves is accepted, so one artifact is
     * reachable under exactly one path. Otherwise "+2" / "+02" / a needless split /
     * chunks cut anywhere would each forge and store their own copy of the same
     * image — a cheap way for an anonymous client to fill the disk.
     */
    public function testEveryGeneratedUrlIsAcceptedBackAsCanonical(): void
    {
        $mc = $this->cache();
        foreach ([40, 200, 260, 400, 900, 2000] as $len) {
            $url = $mc->getCachedUrl('pT', 'img_upload/123/' . str_repeat('q', $len) . '.jpg?w=100&h=100&zc=1');
            self::assertNotNull($mc->parseRequest($url), "round trip rejected for length $len");
        }
    }

    /**
     * A source directory whose first segment reads like a marker ("+5") sits in the
     * marker's own slot, because srcDir goes into the URL RAW — only the filename is
     * encode()d. Such a URL must still round-trip: the marker is emitted even when
     * the name needs no split ("+1"), purely to disambiguate.
     *
     * @return array<string, array{0:string}>
     */
    public static function ambiguousSrcDirProvider(): array
    {
        return [
            'marker-like dir, short name' => ['img_upload/+5/photo.jpg'],
            'marker-like dir, nested'     => ['img_upload/+5/sub/photo.jpg'],
            'marker-like dir, long name'  => ['img_upload/+5/' . str_repeat('z', 400) . '.jpg'],
            'dir merely starting with +'  => ['img_upload/+notanumber/photo.jpg'],
        ];
    }

    #[DataProvider('ambiguousSrcDirProvider')]
    public function testSourceDirectoryThatLooksLikeAMarkerStillRoundTrips(string $srcPath): void
    {
        $mc  = $this->cache();
        $url = $mc->getCachedUrl('pT', $srcPath . '?w=10');

        $req = $mc->parseRequest($url);

        self::assertNotNull($req, "round trip broken for: $url");
        self::assertSame(basename($srcPath), $req->srcName);
        self::assertSame(substr(\dirname($srcPath), \strlen('img_upload/')), $req->srcDir);
    }

    /** The disambiguating marker is emitted ONLY where it is needed. */
    public function testMarkerIsOmittedForAnOrdinaryDirectory(): void
    {
        $url = $this->cache()->getCachedUrl('pT', 'img_upload/123/photo.jpg?w=10');

        self::assertStringNotContainsString('/+', $url);
    }

    /** ...and a marker on a URL that does not need one is rejected, so there is still
     *  exactly one spelling per artifact. */
    public function testGratuitousMarkerOnAnOrdinaryDirectoryIsRejected(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->cache()->parseRequest('/img_upload/mC/pT/+1/123/photo.jpg!w=10.jpg');
    }

    /** Conversely, omitting the marker where it IS needed must be rejected too. */
    public function testMissingMarkerOnAnAmbiguousDirectoryIsRejected(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->cache()->parseRequest('/img_upload/mC/pT/+5/photo.jpg!w=10.jpg');
    }

    /**
     * "+0" and "+02" are NOT markers — the marker is a canonical decimal from 1 up.
     * They stay ordinary directory names, which is right: they are indistinguishable
     * from any other non-existent source directory and get the usual placeholder,
     * rather than opening a second spelling of a real artifact's path.
     */
    public function testNonCanonicalMarkerIsTreatedAsAnOrdinaryDirectory(): void
    {
        foreach (['+0', '+02'] as $notAMarker) {
            $req = $this->cache()->parseRequest("/img_upload/mC/pT/$notAMarker/123/photo.jpg!w=10.jpg");

            self::assertNotNull($req);
            self::assertSame("$notAMarker/123", $req->srcDir, 'must be read as a directory, not a split marker');
            self::assertSame('photo.jpg', $req->srcName);
        }
    }
}
