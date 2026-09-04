<?php

namespace MissCache;

use MissCache\Util\CachePurger;
use MissCache\Util\CacheRequest;
use MissCache\Util\PluginInterface;

/**
 * MissCache — "it only misses once".
 *
 * Forge-on-miss static cache: a dynamic artifact (resized image, minified
 * asset, generated PDF, ...) is produced only on the first request and stored
 * as a plain file under a public, source-mirroring cache tree. Every later
 * request for the same artifact is served directly by the web server, never
 * touching PHP (configure `try_files` / `RewriteCond !-f` to route only the
 * misses to the dispatcher — see README).
 *
 * Two entry points:
 *   - {@see getCachedUrl()}   render time: returns the public URL of the artifact
 *                             (pure string build; never generates).
 *   - {@see handleRequest()}  request time: on a miss, generates the artifact via
 *                             the matching plugin and streams it to the client.
 *
 * Cache URL layout:
 *   {baseUrl}/{cacheSegment}/{routePrefix}/{srcDir}/{enc(srcName)}!{enc(param1)}!...!{enc(paramN)}.{outExt}
 * e.g.
 *   /img_upload/mC/pT/123/photo.jpg!w=150!h=150!zc=1.jpg
 */
final class MissCache
{
    /**
     * Output extensions a cache artifact may have (gate against writing .php/.htaccess/... into the public cache).
     * Kept in sync with what a plugin can actually emit — currently only raster images
     * ({@see outExtFromParams}). A wider list (svg/css/js/pdf) would let a hand-crafted URL
     * cache real image bytes under a mismatched content-type (e.g. JPEG served as text/css);
     * add an extension here only when a plugin genuinely produces that type.
     */
    private const ALLOWED_EXT = ['jpg', 'jpeg', 'png', 'gif', 'webp', 'avif', 'bmp', 'ico'];

    /** max-age (seconds) advertised on the one PHP miss-serve; later hits are static (web-server controlled). */
    private const CACHE_MAX_AGE = 604800; // 7 days

    /**
     * Segment that announces the filename occupying the following N path
     * components. It sits at a FIXED position — immediately after the route prefix
     * — so the parse is positional rather than a search.
     *
     * Position alone is not enough to keep it unambiguous, though: srcDir goes into
     * the URL RAW (only the filename passes through {@see CacheRequest::encode()},
     * whose alphabet excludes "+"), so a source directory literally named "+5" sits
     * in exactly that slot. The marker's PRESENCE is therefore canonical too — see
     * {@see needsSplitMarker()} — which both disambiguates that case and stops one
     * artifact from being reachable under two spellings.
     */
    private const SPLIT_MARKER = '+';

    /** A srcDir whose first segment would be read as a split marker. */
    private const AMBIGUOUS_SRCDIR = '~^\\+[1-9][0-9]*+(?:/|$)~';

    private string $baseUrl;        // public base, no trailing slash, e.g. "https://x/img_upload"
    private string $basePath;       // filesystem base, no trailing slash, e.g. "/var/www/x/img_upload"
    private string $cacheSegment;   // cache subdir, no slashes, e.g. "mC"
    private int $dirMode;
    /** @var array<string, PluginInterface> indexed by route prefix */
    private array $plugins = [];
    private string $publicPrefix;   // URL path that every cache URL starts with, e.g. "/img_upload/mC/"
    private string $srcBase;        // docroot-relative base shared by cache and sources, e.g. "img_upload"
    private int $maxSegmentLength;  // NAME_MAX of the filesystem holding the cache

    /**
     * @param array<int, PluginInterface> $plugins
     * @param int $maxSegmentLength  Longest single path component the cache filesystem accepts.
     *                               255 on ext4/xfs/btrfs; lower it (eCryptfs caps it at 143) and
     *                               longer names are split over more components instead of failing.
     */
    public function __construct(string $baseUrl, string $basePath, string $cacheSegment, int $dirMode, array $plugins, int $maxSegmentLength = 255)
    {
        if ($maxSegmentLength < 16) {
            throw new \InvalidArgumentException('maxSegmentLength must leave room for a name');
        }
        $this->maxSegmentLength = $maxSegmentLength;
        $this->baseUrl      = rtrim($baseUrl, '/');
        $this->basePath     = rtrim($basePath, '/');
        $this->cacheSegment = trim($cacheSegment, '/');
        $this->dirMode      = $dirMode;

        foreach ($plugins as $plugin) {
            if (!$plugin instanceof PluginInterface) {
                throw new \InvalidArgumentException('Plugins must implement ' . PluginInterface::class);
            }
            $this->plugins[$plugin->getRoutePrefix()] = $plugin;
        }

        $urlPath            = rtrim((string) parse_url($baseUrl, PHP_URL_PATH), '/'); // "/img_upload" or ""
        $this->publicPrefix = $urlPath . '/' . $this->cacheSegment . '/';
        $this->srcBase      = trim($urlPath, '/');                                    // "img_upload"
    }

    /**
     * Render-time: build the public URL of the cache artifact for $srcWithQuery.
     * Pure string operation — it does NOT generate anything.
     *
     * @param string $routePrefix   plugin route prefix, e.g. "pT"
     * @param string $srcWithQuery  "img_upload/123/photo.jpg?w=150&h=150&zc=1"
     */
    public function getCachedUrl(string $routePrefix, string $srcWithQuery): string
    {
        if (!isset($this->plugins[$routePrefix])) {
            throw new \RuntimeException("No plugin registered for route prefix: $routePrefix");
        }

        [$srcPath, $params] = array_pad(explode('?', $srcWithQuery, 2), 2, '');
        $srcPath = trim($srcPath, '/');

        // Sources live under the same base as the cache (e.g. img_upload); mirror
        // them RELATIVE to that base so the base is not repeated in the cache path.
        // The dispatcher re-adds $srcBase when rebuilding the backend src.
        if ($this->srcBase !== '' && str_starts_with($srcPath, $this->srcBase . '/')) {
            $srcPath = substr($srcPath, strlen($this->srcBase) + 1);
        }

        $slash   = strrpos($srcPath, '/');
        $srcDir  = $slash === false ? '' : substr($srcPath, 0, $slash);
        $srcName = $slash === false ? $srcPath : substr($srcPath, $slash + 1);

        $outExt   = self::outExtFromParams($params);
        $filename = CacheRequest::buildFilename($srcName, $params, $outExt);

        // A name too long for one path component is spread over several; below the
        // cap this adds nothing, so existing cache URLs stay byte-identical.
        $chunks = CacheRequest::splitFilename($filename, $this->maxSegmentLength);
        $marker = self::needsSplitMarker($chunks, $srcDir) ? '/' . self::SPLIT_MARKER . \count($chunks) : '';

        return $this->baseUrl . '/' . $this->cacheSegment . '/' . $routePrefix . $marker
            . ($srcDir !== '' ? '/' . $srcDir : '') . '/' . implode('/', $chunks);
    }

    /**
     * Request-time: handle a request for a (missing) cache artifact. Generates it
     * via the matching plugin and streams it to the client.
     *
     * @return bool true if the request was a MissCache URL and was handled
     *              (generated & served, or answered with an error status);
     *              false if $requestUri is not a MissCache URL (caller continues).
     */
    public function handleRequest(string $requestUri): bool
    {
        try {
            $req = $this->parseRequest($requestUri);
        } catch (\RuntimeException $e) {
            http_response_code(404);
            return true;
        }
        if ($req === null) {
            return false; // not a MissCache URL
        }

        $plugin = $this->plugins[$req->routePrefix] ?? null;
        if ($plugin === null) {
            http_response_code(404);
            return true;
        }

        $bytes = $plugin->generate($req);
        if ($bytes === null || $bytes === '') {
            http_response_code(500);
            return true;
        }

        // Storing is best-effort, delivering is not: a cache that could not write
        // (full disk, read-only mount, a name over the filesystem's NAME_MAX, wrong
        // permissions) must still answer with the artifact it just forged. Failing
        // to store costs performance — this miss will recur — never a broken image.
        if (is_file($req->filesystemPath)) {
            $this->serve($req->filesystemPath, $req->outExt);
        } else {
            self::serveUnstored($bytes, $req->outExt);
        }
        return true;
    }

    /**
     * Evict cache artifacts (run periodically, e.g. once a day from a scheduler).
     *
     * The walk is per plugin: every route-prefix directory under the cache root is
     * purged on its own, with $options merged with that plugin's
     * {@see PluginInterface::getPurgeOptions()} (the plugin wins for the keys it
     * declares). A directory with no registered plugin — a retired one — is purged
     * with the caller's defaults rather than skipped, so a dead tree cannot become
     * immortal.
     *
     * The size cap is therefore PER PLUGIN, not one budget across the whole tree:
     * total disk use is the sum of the caps. That is the deliberate price of
     * keeping each purge a single streaming walk; one global cap would need a
     * second pass buffering every survivor of every plugin.
     *
     * Nothing outside those directories is ever touched — see {@see routeDirs()}.
     *
     * @param array{maxAge?:int,maxBytes?:?int,lowWatermark?:float,tmpMaxAge?:int,dryRun?:bool} $options
     * @return array{scanned:int,deleted_age:int,deleted_size:int,deleted_tmp:int,bytes_freed:int,dirs_removed:int,total_after:int}
     */
    public function purge(array $options = []): array
    {
        $stats = [
            'scanned' => 0, 'deleted_age' => 0, 'deleted_size' => 0, 'deleted_tmp' => 0,
            'bytes_freed' => 0, 'dirs_removed' => 0, 'total_after' => 0,
        ];

        $cacheRoot = $this->basePath . '/' . $this->cacheSegment;
        foreach (self::routeDirs($cacheRoot) as $prefix) {
            $plugin = $this->plugins[$prefix] ?? null;
            $opts   = $plugin === null ? $options : array_merge($options, $plugin->getPurgeOptions());

            foreach ((new CachePurger($cacheRoot . '/' . $prefix))->purge($opts) as $key => $value) {
                $stats[$key] += $value;
            }
        }

        return $stats;
    }

    /**
     * Route-prefix directories sitting directly under the cache root.
     *
     * Everything a plugin writes lives at least one level below the cache root —
     * {@see parseRequest()} rejects a path with no segment after the route prefix —
     * so the root itself holds nothing but these directories. Purging only inside
     * them therefore loses no artifact, and it makes the root the safe home for the
     * .htaccess that routes misses here in the first place, for a CACHEDIR.TAG or a
     * README: the purge never looks at it.
     *
     * Dot-directories (.svn, .git, ...) are skipped — they are not cache, and
     * deleting their contents would corrupt a working copy. So are symlinks, which
     * would let the walk leave the cache tree.
     *
     * @return list<string>
     */
    private static function routeDirs(string $cacheRoot): array
    {
        $entries = @scandir($cacheRoot);
        if ($entries === false) {
            return [];
        }

        $dirs = [];
        foreach ($entries as $name) {
            if (str_starts_with($name, '.')) {   // also covers scandir's "." and ".."
                continue;
            }
            $path = $cacheRoot . '/' . $name;
            if (is_dir($path) && !is_link($path)) {
                $dirs[] = $name;
            }
        }
        return $dirs;
    }

    /**
     * Parse a request URI into a {@see CacheRequest}, or null if it is not a
     * MissCache URL. Throws \RuntimeException on a malformed/illegal cache URL
     * (e.g. path traversal). Pure — performs no I/O.
     */
    public function parseRequest(string $requestUri): ?CacheRequest
    {
        $path = parse_url($requestUri, PHP_URL_PATH);
        if (!is_string($path) || !str_starts_with($path, $this->publicPrefix)) {
            return null;
        }

        $remainder = substr($path, strlen($this->publicPrefix)); // "pT/img_upload/123/enc!enc.jpg"

        // Path-traversal / null-byte / percent-encoding guard on the literal path.
        // parse_url() does NOT percent-decode, and a legit cache path never contains
        // "%" (tilde-hex uses "~HH", not "%HH"), so reject it outright — this also
        // closes encoded traversal (%2e%2e) and encoded slashes (%2f).
        if (str_contains($remainder, "\0") || str_contains($remainder, '%')
            || preg_match('~(^|/)\.\.(/|$)~', $remainder)) {
            throw new \RuntimeException('Illegal cache path');
        }

        $slash = strpos($remainder, '/');
        if ($slash === false) {
            throw new \RuntimeException('Illegal cache path: no target after route prefix');
        }
        $routePrefix = substr($remainder, 0, $slash);
        $rest        = substr($remainder, $slash + 1); // "img_upload/123/enc!enc.jpg"
        if ($rest === '') {
            throw new \RuntimeException('Illegal cache path: empty target');
        }

        // A "+N" segment here (fixed position, right after the route prefix) means the
        // filename was too long for one path component and occupies the last N of them.
        $chunkCount = 1;
        $hasMarker  = false;
        if (preg_match('~^\+([1-9][0-9]*+)/~', $rest, $m)) {   // canonical decimal only: no "+02", no "+0"
            $hasMarker  = true;
            $chunkCount = (int) $m[1];
            $rest       = substr($rest, \strlen($m[0]));
        }

        $segments = explode('/', $rest);
        if (\count($segments) < $chunkCount) {
            throw new \RuntimeException('Illegal cache path: fewer segments than the split announces');
        }
        $chunks = \array_splice($segments, -$chunkCount);
        foreach ($chunks as $chunk) {
            if ($chunk === '') {
                throw new \RuntimeException('Illegal cache path: empty segment');
            }
        }
        $dir  = implode('/', $segments);
        $file = CacheRequest::joinFilename($chunks);

        // Accept only the split we would have emitted ourselves. Without this, one
        // artifact is reachable under many paths — "+2" vs "+02", a split that was
        // never needed, or chunks cut anywhere — and each of them forges and stores
        // its own copy, which is a cheap way for an anonymous client to fill the
        // disk. Comparing against splitFilename() makes the accepted URL space
        // exactly the image of getCachedUrl(): one canonical path per artifact.
        if ($chunks !== CacheRequest::splitFilename($file, $this->maxSegmentLength)
            || $hasMarker !== self::needsSplitMarker($chunks, $dir)) {
            throw new \RuntimeException('Non-canonical cache path');
        }

        [$srcName, $params, $outExt] = CacheRequest::parseFilename($file);

        // Decoded source name must be a pure basename (no path parts).
        if ($srcName === '' || strpbrk($srcName, "/\\\0") !== false || str_contains($srcName, '..')) {
            throw new \RuntimeException('Illegal source name');
        }

        // Only ever write known static-asset extensions into the public cache.
        if (!in_array(strtolower($outExt), self::ALLOWED_EXT, true)) {
            throw new \RuntimeException('Illegal output extension');
        }

        $cacheRoot      = $this->basePath . '/' . $this->cacheSegment . '/';
        $filesystemPath = $cacheRoot . $remainder;

        // Defence in depth: never resolve outside the cache root.
        if (!str_starts_with($filesystemPath, $cacheRoot)) {
            throw new \RuntimeException('Resolved path escapes cache root');
        }

        // Absolute path of the source on disk (sources mirror the cache under basePath).
        // Only correct for sources that actually live under basePath; a plugin must
        // treat it as a hint (e.g. gate on the parent dir existing), not as truth.
        $sourceFsPath = $this->basePath . '/' . ($dir !== '' ? $dir . '/' : '') . $srcName;

        return new CacheRequest($routePrefix, $dir, $srcName, $params, $outExt, $filesystemPath, $this->dirMode, $this->srcBase, $sourceFsPath);
    }

    /**
     * Whether a cache URL for these chunks under this srcDir carries the "+N" marker.
     *
     * True when the filename really is split, and also when srcDir's first segment
     * would itself be read as a marker — a source directory named "+5" occupies the
     * marker's slot, so without the marker its URLs parse back wrong. Both sides of
     * the round trip ask this one function, so they cannot drift.
     *
     * @param list<string> $chunks
     */
    private static function needsSplitMarker(array $chunks, string $srcDir): bool
    {
        return \count($chunks) > 1 || preg_match(self::AMBIGUOUS_SRCDIR, $srcDir) === 1;
    }

    /**
     * Stream the forged artifact with caching headers. This runs only for the
     * single miss response; every later request is served statically by the web
     * server. We emit Last-Modified + Cache-Control (and honour If-Modified-Since
     * with a 304) so this first response is as cacheable as the static hits that
     * follow. Last-Modified mirrors the file mtime — the exact validator the web
     * server uses for the static file — so a client revalidating after the file
     * goes static gets a clean 304 across the PHP→static boundary. We deliberately
     * do NOT emit an ETag: the static server computes its own (inode/size/mtime)
     * ETag that we cannot portably reproduce, so a PHP-issued ETag would simply
     * fail to match on the next revalidation and force a needless full download.
     */
    private function serve(string $path, string $ext): void
    {
        clearstatcache(true, $path);
        $mtime = filemtime($path);
        $size  = filesize($path);
        if ($mtime === false || $size === false) {
            // The artifact vanished between handleRequest()'s is_file() check and
            // here (e.g. a concurrent purge) — don't serve a mangled response.
            http_response_code(500);
            return;
        }
        $headers = self::cacheHeaders($ext, $mtime, $size);

        if (self::isClientCacheFresh($mtime)) {
            if (!headers_sent()) {
                http_response_code(304);
                header('Last-Modified: ' . $headers['Last-Modified']);
                header('Cache-Control: ' . $headers['Cache-Control']);
            }
            return; // 304: no body
        }

        if (!headers_sent()) {
            foreach ($headers as $name => $value) {
                header($name . ': ' . $value);
            }
        }
        readfile($path);
    }

    /**
     * Send an artifact that was forged but could NOT be stored.
     *
     * No Last-Modified and no 304 handling: those validators exist to line up with
     * the ones the web server will issue for the static file, and here there is no
     * static file to line up with. Cache-Control is still worth sending — the bytes
     * are a pure function of the URL, so a client may reuse them exactly as long as
     * it would a stored artifact, and every client that does spares us a re-forge.
     */
    private static function serveUnstored(string $bytes, string $ext): void
    {
        if (!headers_sent()) {
            header('Content-Type: ' . self::mimeForExt($ext));
            header('Content-Length: ' . strlen($bytes));
            header('Cache-Control: public, max-age=' . self::CACHE_MAX_AGE);
        }
        echo $bytes;
    }

    /**
     * Cache headers for a forged artifact. Pure (no I/O, no globals) so it is
     * unit-testable.
     *
     * @return array{Content-Type:string, Content-Length:string, Last-Modified:string, Cache-Control:string}
     */
    private static function cacheHeaders(string $ext, int $mtime, int $size): array
    {
        return [
            'Content-Type'   => self::mimeForExt($ext),
            'Content-Length' => (string) $size,
            'Last-Modified'  => gmdate('D, d M Y H:i:s', $mtime) . ' GMT',
            'Cache-Control'  => 'public, max-age=' . self::CACHE_MAX_AGE,
        ];
    }

    /**
     * Whether the client's If-Modified-Since covers the artifact's mtime (→ 304).
     * $server defaults to $_SERVER but is injectable for testing.
     *
     * @param array<string,mixed>|null $server
     */
    private static function isClientCacheFresh(int $mtime, ?array $server = null): bool
    {
        $ifModifiedSince = trim((string) (($server ?? $_SERVER)['HTTP_IF_MODIFIED_SINCE'] ?? ''));
        if ($ifModifiedSince === '') {
            return false;
        }
        $since = strtotime($ifModifiedSince);
        return $since !== false && $mtime <= $since;
    }

    /** Output extension derived from the phpThumb "f" param (defaults to jpg). */
    private static function outExtFromParams(string $params): string
    {
        if ($params === '') {
            return 'jpg';
        }
        parse_str($params, $a);
        $f = isset($a['f']) ? strtolower((string) $a['f']) : '';
        return match ($f) {
            'png', 'gif', 'webp', 'avif', 'bmp', 'ico' => $f,
            default => 'jpg', // '', 'jpg', 'jpeg' and anything unknown
        };
    }

    /** Content type for an output extension. Arms must stay in sync with {@see ALLOWED_EXT}. */
    private static function mimeForExt(string $ext): string
    {
        return match (strtolower($ext)) {
            'jpg', 'jpeg' => 'image/jpeg',
            'png'  => 'image/png',
            'gif'  => 'image/gif',
            'webp' => 'image/webp',
            'avif' => 'image/avif',
            'bmp'  => 'image/bmp',
            'ico'  => 'image/x-icon',
            default => 'application/octet-stream',
        };
    }
}
