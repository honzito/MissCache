<?php

declare(strict_types=1);

namespace MissCache\Tests;

use MissCache\MissCache;
use MissCache\Plugins\PhpThumbPlugin;
use PHPUnit\Framework\TestCase;

final class MissCacheTest extends TestCase
{
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

    public function testParseRequestRejectsPercentEncoding(): void
    {
        // Percent-encoding never appears in a real cache path (tilde-hex uses ~HH).
        $this->expectException(\RuntimeException::class);
        $this->mc()->parseRequest('/img_upload/mC/pT/img_upload/%2e%2e/photo.jpg');
    }
}
