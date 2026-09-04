<?php

declare(strict_types=1);

namespace MissCache\Tests;

use MissCache\Util\CacheRequest;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Splitting an over-long cache filename across path segments.
 *
 * NAME_MAX caps ONE path component at 255 bytes; PATH_MAX caps the whole path at
 * 4096. A filename that would exceed a component is therefore spread over several
 * of them. This is not cosmetic: measured on actionapps.org, a cache URL whose
 * last segment is 260 bytes is answered 403 by Apache itself — its rewrite does a
 * stat() on the segment, gets ENAMETOOLONG, and PHP is never reached. No PHP-side
 * fix can rescue such a URL; only keeping every segment short can.
 *
 * Invariants pinned here:
 *  - a name within the cap is untouched (byte-identical URLs to before, so no
 *    existing cache entry is orphaned);
 *  - every emitted segment is within the cap;
 *  - the segments are as equal in length as possible (no 1-character tail);
 *  - the EXTENSION rides on the last segment, so the static web server still
 *    types the response correctly on the hits that never touch PHP;
 *  - the split is exactly reversible.
 */
final class CacheRequestSplitTest extends TestCase
{
    private const MAX = 255;

    public function testShortNameIsNotSplit(): void
    {
        $name = 'photo.jpg!w=150!h=150!zc=1.jpg';

        self::assertSame([$name], CacheRequest::splitFilename($name, self::MAX));
    }

    public function testNameExactlyAtTheCapIsNotSplit(): void
    {
        $name = str_repeat('a', self::MAX - 4) . '.jpg';
        self::assertSame(self::MAX, \strlen($name));

        self::assertSame([$name], CacheRequest::splitFilename($name, self::MAX));
    }

    /** @return array<string, array{0:string}> */
    public static function longNameProvider(): array
    {
        return [
            'one over the cap'      => [str_repeat('a', self::MAX - 3) . '.jpg'],
            'just under two caps'   => [str_repeat('b', 500) . '.jpg'],
            'three segments'        => [str_repeat('c', 700) . '.webp'],
            'many segments'         => [str_repeat('d', 5000) . '.png'],
            'no extension'          => [str_repeat('e', 400)],
            'realistic biom name'   => [
                str_repeat('cilem-je-zajistit-aby-biomasa-pro-energetiku-', 8) . '.jpg!w=680!h=453!zc=1!f=webp.webp',
            ],
        ];
    }

    #[DataProvider('longNameProvider')]
    public function testEverySegmentIsWithinTheCap(string $name): void
    {
        foreach (CacheRequest::splitFilename($name, self::MAX) as $i => $chunk) {
            self::assertLessThanOrEqual(self::MAX, \strlen($chunk), "segment $i over the cap");
            self::assertNotSame('', $chunk, "segment $i is empty");
        }
    }

    #[DataProvider('longNameProvider')]
    public function testSegmentsAreAsEqualAsPossible(string $name): void
    {
        $lengths = array_map(strlen(...), CacheRequest::splitFilename($name, self::MAX));

        self::assertLessThanOrEqual(
            1,
            max($lengths) - min($lengths),
            'segment lengths must differ by at most one byte, got: ' . implode(', ', $lengths),
        );
    }

    #[DataProvider('longNameProvider')]
    public function testExtensionRidesOnTheLastSegment(string $name): void
    {
        $chunks = CacheRequest::splitFilename($name, self::MAX);
        $dot    = strrpos($name, '.');
        if ($dot === false) {
            self::assertStringNotContainsString('.', end($chunks));
            return;
        }
        self::assertStringEndsWith(substr($name, $dot), end($chunks), 'static server needs the extension');
    }

    #[DataProvider('longNameProvider')]
    public function testSplitIsReversible(string $name): void
    {
        self::assertSame($name, implode('', CacheRequest::splitFilename($name, self::MAX)));
    }

    /** The number of segments must be the minimum the cap allows — no gratuitous depth. */
    public function testSegmentCountIsMinimal(): void
    {
        $name = str_repeat('a', 500) . '.jpg';   // 504 bytes over a 255 cap -> exactly 2

        self::assertCount(2, CacheRequest::splitFilename($name, self::MAX));
    }

    /** A smaller cap (eCryptfs NAME_MAX is 143, not 255) must be honoured. */
    public function testSmallerCapIsHonoured(): void
    {
        $name   = str_repeat('a', 400) . '.jpg';
        $chunks = CacheRequest::splitFilename($name, 143);

        self::assertGreaterThanOrEqual(3, \count($chunks));
        foreach ($chunks as $chunk) {
            self::assertLessThanOrEqual(143, \strlen($chunk));
        }
        self::assertSame($name, implode('', $chunks));
    }
}
