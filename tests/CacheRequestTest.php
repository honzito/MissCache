<?php

declare(strict_types=1);

namespace MissCache\Tests;

use MissCache\Util\CacheRequest;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class CacheRequestTest extends TestCase
{
    public function testEncodeKeepsSafeCharsLiteral(): void
    {
        self::assertSame('photo.jpg', CacheRequest::encode('photo.jpg'));
        self::assertSame('a-b_c.9Z', CacheRequest::encode('a-b_c.9Z'));
        self::assertSame('w=150,h=150', CacheRequest::encode('w=150,h=150')); // '=' and ',' stay literal
    }

    public function testEncodeEscapesUnsafeBytes(): void
    {
        self::assertSame('w=150~-h=150', CacheRequest::encode('w=150&h=150')); // '&' -> '~-'
        self::assertSame('~7E', CacheRequest::encode('~'));                    // tilde itself is escaped
        self::assertSame('a~2Fb', CacheRequest::encode('a/b'));                // '/' stays hex
    }

    #[DataProvider('roundtripStrings')]
    public function testEncodeDecodeRoundtrip(string $s): void
    {
        self::assertSame($s, CacheRequest::decode(CacheRequest::encode($s)));
    }

    public static function roundtripStrings(): array
    {
        return [
            ['photo.jpg'],
            ['w=150&h=150&zc=1'],
            ['Černá Kočka (1).jpeg'],
            ['fltr[]=blur|9&fltr[]=sharpen'],
            ['spaces and #hash %25'],
            [''],
        ];
    }

    public function testDecodeRejectsBadEscape(): void
    {
        $this->expectException(\RuntimeException::class);
        CacheRequest::decode('bad~XYescape');
    }

    public function testBuildParseRoundtripWithParams(): void
    {
        // Params are "!"-separated (one per "&"), so a plain query has no "~-" at all.
        $fn = CacheRequest::buildFilename('photo.jpg', 'w=150&h=150&zc=1', 'jpg');
        self::assertSame('photo.jpg!w=150!h=150!zc=1.jpg', $fn);
        self::assertSame(['photo.jpg', 'w=150&h=150&zc=1', 'jpg'], CacheRequest::parseFilename($fn));
    }

    public function testBuildParseRoundtripWithSpecialParamChars(): void
    {
        // "!" inside a value is escaped to "~21" so it never collides with the
        // structural "!" separator; brackets/pipes survive the round-trip.
        $params = 'fltr[]=blur|9&w=100';
        $fn     = CacheRequest::buildFilename('a!b.jpg', $params, 'png');
        [$srcName, $parsedParams, $ext] = CacheRequest::parseFilename($fn);
        self::assertSame('a!b.jpg', $srcName);   // literal "!" in the name survives
        self::assertSame($params, $parsedParams);
        self::assertSame('png', $ext);
    }

    public function testBuildParseRoundtripWithoutParams(): void
    {
        $fn = CacheRequest::buildFilename('photo.jpg', null, 'jpg');
        self::assertSame(['photo.jpg', null, 'jpg'], CacheRequest::parseFilename($fn));
    }

    public function testParseFilenameRejectsMissingExtension(): void
    {
        $this->expectException(\RuntimeException::class);
        CacheRequest::parseFilename('noextension');
    }

    public function testToRawQueryString(): void
    {
        $req = new CacheRequest('pT', 'img_upload/123', 'photo.jpg', 'w=150&h=150', 'jpg', '/tmp/x.jpg');
        self::assertSame('src=/img_upload/123/photo.jpg&w=150&h=150', $req->toRawQueryString(true));
        self::assertSame('src=img_upload/123/photo.jpg&w=150&h=150', $req->toRawQueryString(false));
    }
}
