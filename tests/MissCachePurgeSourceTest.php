<?php

declare(strict_types=1);

namespace MissCache\Tests;

use MissCache\MissCache;
use MissCache\Plugins\PhpThumbPlugin;
use PHPUnit\Framework\TestCase;

/**
 * MissCache::purgeSource() - when a source is overwritten or deleted, every artifact made
 * from it goes, and nothing else does. The artifacts are laid out by the library itself
 * (getCachedUrl() -> parseRequest() -> filesystemPath), so the test follows any change
 * of the layout instead of hard-coding it.
 */
final class MissCachePurgeSourceTest extends TestCase
{
    /** short enough that the long-name cases really spread over several path components */
    private const SEGMENT = 40;

    private string $base;

    protected function setUp(): void
    {
        $this->base = sys_get_temp_dir() . '/misscache_purgesource_' . getmypid() . '_' . uniqid();
        mkdir($this->base, 0775, true);
    }

    protected function tearDown(): void
    {
        $it = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($this->base, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($it as $f) {
            $f->isDir() ? @rmdir($f->getPathname()) : @unlink($f->getPathname());
        }
        @rmdir($this->base);
    }

    private function mc(): MissCache
    {
        return new MissCache('https://example.org/img_upload', $this->base, 'mC', 0775, [
            new PhpThumbPlugin('https://example.org/img.php'),
            new PhpThumbPlugin('https://example.org/img.php', 'pX'),   // a second plugin: the purge covers every route
        ], self::SEGMENT);
    }

    /** Store the artifact the cache would forge for $source ("dir/name") with $params; returns its path. */
    private function artifact(string $source, string $params, string $route = 'pT'): string
    {
        $mc   = $this->mc();
        $path = (string) parse_url($mc->getCachedUrl($route, "img_upload/$source?$params"), PHP_URL_PATH);
        $file = $mc->parseRequest($path)->filesystemPath;
        @mkdir(\dirname($file), 0775, true);
        file_put_contents($file, 'x');
        return $file;
    }

    public function testEveryArtifactOfTheSourceGoesAndNothingElse(): void
    {
        $long = str_repeat('w=1&', 12) . 'h=1';   // a query long enough to split the name
        $gone = [
            $this->artifact('123/photo.jpg', 'w=150'),
            $this->artifact('123/photo.jpg', 'w=150&h=150&zc=1&aoe=1'),
            $this->artifact('123/photo.jpg', 'w=150&f=webp'),
            $this->artifact('123/photo.jpg', $long),
            $this->artifact('123/photo.jpg', 'w=150', 'pX'),
        ];
        $kept = [
            $this->artifact('123/photo.jpg2', 'w=150'),    // longer name sharing the prefix
            $this->artifact('123/photo', 'w=150'),         // shorter name the prefix contains
            $this->artifact('123/photo.png', 'w=150'),
            $this->artifact('123/photo.jpg2', $long),      // split, and its first chunk still spells "photo.jpg"
            $this->artifact('1234/photo.jpg', 'w=150'),    // same name, another directory
            $this->artifact('photo.jpg', 'w=150'),         // same name, directly under the base
        ];
        self::assertStringContainsString('/+', $gone[3], 'test premise: the long variant is split');
        self::assertStringContainsString('/+', $kept[3], 'test premise: the long variant is split');

        self::assertSame(\count($gone), $this->mc()->purgeSource($this->base . '/123/photo.jpg'));

        foreach ($gone as $file) {
            self::assertFileDoesNotExist($file);
        }
        foreach ($kept as $file) {
            self::assertFileExists($file);
        }
    }

    public function testSourceDirectlyUnderTheBase(): void
    {
        $gone = $this->artifact('photo.jpg', 'w=150');
        $kept = $this->artifact('123/photo.jpg', 'w=150');

        self::assertSame(1, $this->mc()->purgeSource($this->base . '/photo.jpg'));
        self::assertFileDoesNotExist($gone);
        self::assertFileExists($kept);
    }

    /** A source directory named like a split marker is written under "+1" - it must still be found. */
    public function testSourceDirectoryLookingLikeASplitMarker(): void
    {
        $gone = $this->artifact('+5/photo.jpg', 'w=150');
        self::assertStringContainsString('/mC/pT/+1/+5/', $gone, 'test premise: the marker disambiguates the directory');

        self::assertSame(1, $this->mc()->purgeSource($this->base . '/+5/photo.jpg'));
        self::assertFileDoesNotExist($gone);
    }

    /** @return array<string, array{0: string}> */
    public static function foreignPaths(): array
    {
        return [
            'outside the base'      => ['/etc/passwd'],
            'sibling of the base'   => ['{base}-other/123/photo.jpg'],
            'the base itself'       => ['{base}/'],
            'parent segment'        => ['{base}/123/../123/photo.jpg'],
            'no file name'          => ['{base}/123/'],
        ];
    }

    /** "a//b" and "a/./b" name the very same file */
    public function testSloppyButEqualSpellingOfTheSourceIsFound(): void
    {
        $gone = $this->artifact('123/photo.jpg', 'w=150');
        self::assertSame(1, $this->mc()->purgeSource($this->base . '/./123//photo.jpg'));
        self::assertFileDoesNotExist($gone);
    }

    /**
     * A source directory named like a split marker ("+2/<25 a>") must not reach into the
     * split tree of another source: "<25 a>photo.jpg" with a 40-byte segment is stored as
     * "+2/<25 a>/photo.jpg!w=150!h=150.jpg".
     */
    public function testMarkerLikeDirectoryLeavesOtherSplitNamesAlone(): void
    {
        $other = $this->artifact(str_repeat('a', 25) . 'photo.jpg', 'w=150&h=150');
        self::assertStringContainsString('/mC/pT/+2/' . str_repeat('a', 25) . '/photo.jpg!w=150!h=150.jpg', $other, 'test premise');

        self::assertSame(0, $this->mc()->purgeSource($this->base . '/+2/' . str_repeat('a', 25) . '/photo.jpg'));
        self::assertFileExists($other);
    }

    /** a file next to the artifacts which only looks like one of a source without an extension */
    public function testFilesWhichAreNotArtifactsStay(): void
    {
        $dir = $this->base . '/mC/pT/123';
        mkdir($dir, 0775, true);
        foreach (['index.html', 'README.txt', 'web.config', 'CACHEDIR.TAG'] as $file) {
            touch("$dir/$file");
        }
        foreach (['index', 'README', 'web', 'CACHEDIR'] as $source) {
            self::assertSame(0, $this->mc()->purgeSource($this->base . "/123/$source"));
        }
        self::assertCount(4, glob("$dir/*"));
    }

    /** a planted symlink must not take the deleting out of the cache tree */
    public function testSymlinkedDirectoryIsNotFollowed(): void
    {
        $outside = $this->base . '/outside';
        mkdir($outside);
        file_put_contents("$outside/photo.jpg!w=150.jpg", 'x');
        mkdir($this->base . '/mC/pT', 0775, true);
        symlink($outside, $this->base . '/mC/pT/123');

        self::assertSame(0, $this->mc()->purgeSource($this->base . '/123/photo.jpg'));
        self::assertFileExists("$outside/photo.jpg!w=150.jpg");
        unlink($this->base . '/mC/pT/123');
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('foreignPaths')]
    public function testPathWhichIsNotASourceDeletesNothing(string $path): void
    {
        $kept = $this->artifact('123/photo.jpg', 'w=150');

        self::assertSame(0, $this->mc()->purgeSource(str_replace('{base}', $this->base, $path)));
        self::assertFileExists($kept);
    }

    public function testNoCacheYetIsNoError(): void
    {
        self::assertSame(0, $this->mc()->purgeSource($this->base . '/123/photo.jpg'));
    }
}
