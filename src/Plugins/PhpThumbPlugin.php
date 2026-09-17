<?php

namespace MissCache\Plugins;

use MissCache\Util\CacheRequest;
use MissCache\Util\PluginInterface;

/**
 * Generates a cache artifact by asking a phpThumb entry point (e.g. AA's
 * img.php) over a local HTTP request and storing the returned image bytes.
 *
 * A subrequest is used rather than including the entry point because phpThumb
 * serves its own cached file and calls exit(), which would prevent capturing
 * the output in-process. The HTTP round-trip happens only on a cache miss
 * (once per artifact); every later request is served statically by the web
 * server.
 *
 * Failure handling: phpThumb normally answers an unusable source (missing,
 * corrupt, unsupported, too large) with its own error image as HTTP 200, which
 * a naive cache would store forever. To avoid that, the entry point (AA's
 * img.php) is configured to redirect on any thumbnailing failure, so an unusable
 * source yields a non-2xx response, generate() returns null and the request is
 * answered with {@see fallback()} - a 1×1 blank of the requested type, which the
 * dispatcher never stores (see {@see PluginInterface::fallback()} for why).
 */
final class PhpThumbPlugin implements PluginInterface
{
    /** The shipped 1×1 blanks, one per output type. */
    private const ASSETS = __DIR__ . '/../../assets';

    /** phpThumb parameters no artifact is made with - see generate() */
    private const FOREIGN_PARAMS = ['src' => true, 'new' => true, 'phpThumbDebug' => true, 'nocache' => true, 'down' => true, 'sia' => true];

    /** @var \Closure(string): array{0:int,1:string|false} */
    private readonly \Closure $fetch;

    /**
     * @param string        $phpThumbEntryUrl absolute URL of the phpThumb entry point, e.g. "https://example.org/apc-aa/img.php"
     * @param string        $routePrefix      route prefix this plugin answers to (first cache-path segment)
     * @param \Closure|null $fetch            replaces the HTTP GET ({@see httpGet()} signature) - for tests without a server
     */
    public function __construct(
        private readonly string $phpThumbEntryUrl,
        private readonly string $routePrefix = 'pT',
        ?\Closure $fetch = null,
    ) {
        $this->fetch = $fetch ?? $this->httpGet(...);
    }

    public function getRoutePrefix(): string
    {
        return $this->routePrefix;
    }

    /**
     * Thumbnails are cheap to re-forge from the original, so the caller's defaults are
     * fine. The one addition reaps the 1×1 blanks an earlier version of this plugin
     * stored as negative-cache entries: a purge keyed on recency would never reclaim
     * one that a live page keeps hitting, so the image stayed blank for good.
     */
    public function getPurgeOptions(): array
    {
        return ['stale' => self::isBlank(...)];
    }

    /** Whether the file at $path is byte-identical to one of the shipped blanks (size compared first, so real artifacts are never read). */
    private static function isBlank(string $path, int $size): bool
    {
        static $blanks = null;   // size => bytes, for the handful of assets
        if ($blanks === null) {
            $blanks = [];
            foreach (glob(self::ASSETS . '/blank.*') ?: [] as $asset) {
                if (($bytes = @file_get_contents($asset)) !== false) {   // never store false: an unreadable cache file would "match" it
                    $blanks[strlen($bytes)] = $bytes;
                }
            }
        }
        return isset($blanks[$size]) && @file_get_contents($path) === $blanks[$size];
    }

    public function generate(CacheRequest $req): ?string
    {
        // Parameters an application never puts into a cache url, only a hand-crafted one: a
        // second src (phpThumb takes the last) renders another file - or a remote url - under
        // this source's name, where purgeSource() of the real one never finds it; new makes an
        // image of no source; the rest answer text or headers a static file cannot keep.
        // parse_str() is what the entry point reads the query with, so every spelling counts.
        parse_str((string) $req->params, $params);
        if (array_intersect_key($params, self::FOREIGN_PARAMS)) {
            return null;
        }

        // A source PHP cannot see right now - deleted, not uploaded yet, or a filesystem
        // refusing us for a moment - cannot yield an image, so skip the round-trip. No
        // need to double-check the parent directory: a wrong "missing" verdict now costs
        // a 60 s blank, not a permanent file.
        if ($req->sourceFsPath !== null && !is_file($req->sourceFsPath)) {
            return null;
        }
        $version = self::version($req->sourceFsPath);

        $url           = $this->phpThumbEntryUrl . '?' . $req->toRawQueryString(true);
        [$code, $body] = ($this->fetch)($url);
        if ($code === 0) {
            // Not a bad image but a bad setup or an outage: the entry point could not be
            // reached from the server itself. The visitor sees the fallback either way;
            // this line is the only trace the operator gets.
            error_log('MissCache: phpThumb entry point unreachable: ' . substr(addcslashes($url, "\0..\37\177"), 0, 300));   // request-derived: keep it one line
            return null;
        }
        if ($code < 200 || $code >= 300 || !is_string($body) || $body === '') {
            return null;   // a failure redirect from the entry point: it could not make an image of this source
        }
        // Store is best-effort; the bytes are the contract either way. Not at all when the
        // source changed while phpThumb rendered it: the upload which replaced it has purged
        // the cache already (MissCache::purgeSource()), and this image is of the old one.
        if (self::version($req->sourceFsPath) === $version) {
            $this->writeFile($req->filesystemPath, $body, $req->dirMode);
        }
        return $body;
    }

    /** @return list<int>|null what tells a replaced (or deleted) source from the one before - null when there is no path to look at */
    private static function version(?string $path): ?array
    {
        if ($path === null) {
            return null;
        }
        clearstatcache(true, $path);
        $stat = @stat($path);
        return ($stat === false) ? [] : [$stat['ino'], $stat['size'], $stat['mtime']];
    }

    /** The shipped 1×1 blank of the requested type (jpeg shares the jpg asset), or null for a type we have none for. */
    public function fallback(CacheRequest $req): ?string
    {
        $ext = strtolower($req->outExt);
        $ext = $ext === 'jpeg' ? 'jpg' : $ext;
        if (!ctype_alnum($ext)) {   // defence in depth: never let an extension escape the assets dir
            return null;
        }
        $bytes = @file_get_contents(self::ASSETS . '/blank.' . $ext);
        return $bytes === false ? null : $bytes;
    }

    /**
     * Atomically store $bytes at $target (creating parent dirs), so a concurrent
     * request never serves a half-written file. Best-effort: false only means the
     * artifact will have to be forged again next time, never that the caller has
     * nothing to serve.
     *
     * The temp name is short and INDEPENDENT of $target — "mc<hex>.tmp" in the same
     * directory, not "<target>.tmp.<hex>". Suffixing the target used to add 21 bytes
     * to a name that is already at most NAME_MAX (255) bytes, so a perfectly
     * storable artifact of 235..255 bytes failed to write with ENAMETOOLONG while
     * $target itself would have fit. Same directory, so the rename stays atomic;
     * random, so concurrent forges still never collide. ".tmp" is outside
     * MissCache::ALLOWED_EXT, so the transient file can never be served as an
     * artifact, and CachePurger reaps any that a crashed forge leaves behind.
     */
    private function writeFile(string $target, string $bytes, int $dirMode): bool
    {
        $dir = \dirname($target);
        if (!self::makeDirectory($dir, $dirMode)) {
            return false;
        }
        $tmp = $dir . '/mc' . bin2hex(random_bytes(8)) . '.tmp';
        if (@file_put_contents($tmp, $bytes) === false) {
            return false;
        }
        if (!@rename($tmp, $target)) {
            @unlink($tmp);
            return false;
        }
        return is_file($target);
    }

    /**
     * mkdir -p which applies $mode whatever the umask of the process and keeps the setgid bit
     * a new directory inherits: PHP users sharing the cache (two application trees, cron)
     * store into each other's directories through the group, and a umask of 022 would take
     * that away. Silent on failure - a warning here would land in front of the image bytes.
     */
    private static function makeDirectory(string $dir, int $mode): bool
    {
        if (is_dir($dir)) {
            return true;
        }
        $parent = \dirname($dir);
        if (($parent === $dir) || !self::makeDirectory($parent, $mode)) {
            return false;
        }
        if (!@mkdir($dir, $mode) && !is_dir($dir)) {   // is_dir() again: a parallel forge may have made it meanwhile
            return false;
        }
        @chmod($dir, $mode | (@fileperms($dir) & 0o2000));   // best-effort: one made by another user needs no chmod from us
        return true;
    }

    /**
     * Fetch $url. Returns [httpStatus, body]; httpStatus is 0 on a transport-level
     * failure (entry point unreachable), in which case body is false. Redirects
     * are never followed — the entry point answers a failed generation with a
     * redirect, and that non-2xx status is our failure signal.
     *
     * @return array{0:int,1:string|false}
     */
    private function httpGet(string $url): array
    {
        if (function_exists('curl_init')) {
            $ch = curl_init($url);
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_FOLLOWLOCATION => false, // a failure redirect is our signal — never follow it
                CURLOPT_PROTOCOLS      => CURLPROTO_HTTP | CURLPROTO_HTTPS,
                CURLOPT_CONNECTTIMEOUT => 10,
                CURLOPT_TIMEOUT        => 30,
            ]);
            $body = curl_exec($ch);
            $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);
            return [$code, is_string($body) ? $body : false];
        }

        $ctx = stream_context_create(['http' => [
            'timeout'         => 30,
            'follow_location' => 0,
            'ignore_errors'   => true, // capture the body/status even on a non-2xx response
        ]]);
        $body = @file_get_contents($url, false, $ctx);
        $code = $this->statusFromHeaders($http_response_header ?? []);
        return [$code, is_string($body) ? $body : false];
    }

    /** Extract the HTTP status code from a $http_response_header array (0 if none). */
    private function statusFromHeaders(array $headers): int
    {
        $code = 0;
        foreach ($headers as $h) {
            if (preg_match('#^HTTP/\S+\s+(\d{3})#', (string) $h, $m)) {
                $code = (int) $m[1]; // follow_location=0 -> single status line
            }
        }
        return $code;
    }
}
