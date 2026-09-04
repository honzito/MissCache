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
 * Failure handling (negative caching): phpThumb normally answers an unusable
 * source (missing/corrupt/unsupported/too-large) with its own error image as
 * HTTP 200, which a naive cache would store forever. To avoid that, the entry
 * point (AA's img.php) is configured to redirect to a blank placeholder on any
 * thumbnailing failure, so an unusable source yields a non-2xx response. On such
 * a response (or a known-missing source) this plugin writes a tiny 1×1
 * placeholder of the requested type, so the miss is resolved once and every
 * later request is served statically instead of re-forging.
 */
final class PhpThumbPlugin implements PluginInterface
{
    /**
     * @param string $phpThumbEntryUrl absolute URL of the phpThumb entry point, e.g. "https://example.org/apc-aa/img.php"
     * @param string $routePrefix      route prefix this plugin answers to (first cache-path segment)
     */
    public function __construct(
        private readonly string $phpThumbEntryUrl,
        private readonly string $routePrefix = 'pT',
    ) {}

    public function getRoutePrefix(): string
    {
        return $this->routePrefix;
    }

    /** Thumbnails are cheap to re-forge from the original, so the caller's defaults are fine. */
    public function getPurgeOptions(): array
    {
        return [];
    }

    public function generate(CacheRequest $req): ?string
    {
        // Fast path: a source whose directory exists but whose file does not is
        // definitively missing — negative-cache a placeholder without a round-trip.
        // (Gated on the parent dir to avoid mistaking an unresolved source path
        // for a missing file; otherwise fall through to the authoritative HTTP probe.)
        if ($req->sourceFsPath !== null
            && is_dir(\dirname($req->sourceFsPath))
            && !is_file($req->sourceFsPath)) {
            return $this->writePlaceholder($req);
        }

        $url           = $this->phpThumbEntryUrl . '?' . $req->toRawQueryString(true);
        [$code, $body] = $this->httpGet($url);

        if ($code >= 200 && $code < 300 && is_string($body) && $body !== '') {
            // Store is best-effort; the bytes are the contract either way.
            $this->writeFile($req->filesystemPath, $body, $req->dirMode);
            return $body;
        }
        if ($code !== 0) {
            // Reached phpThumb, but it could not produce the image -> placeholder.
            return $this->writePlaceholder($req);
        }
        // Transport failure (could not reach the entry point): do not negative-cache
        // a transient error; let the next request retry.
        return null;
    }

    /** Negative-cache: store and return the 1×1 placeholder matching the requested output type. */
    private function writePlaceholder(CacheRequest $req): ?string
    {
        $asset = $this->placeholderAsset($req->outExt);
        if ($asset === null) {
            return null; // no placeholder for this type — cannot negative-cache safely
        }
        $bytes = @file_get_contents($asset);
        if ($bytes === false) {
            return null;
        }
        $this->writeFile($req->filesystemPath, $bytes, $req->dirMode);
        return $bytes;
    }

    /** Absolute path of the shipped placeholder for $ext, or null if none exists. */
    private function placeholderAsset(string $ext): ?string
    {
        $ext = strtolower($ext);
        $ext = $ext === 'jpeg' ? 'jpg' : $ext;
        if (!ctype_alnum($ext)) {   // defence in depth: never let an extension escape the assets dir
            return null;
        }
        $file = \dirname(__DIR__, 2) . '/assets/blank.' . $ext;
        return is_file($file) ? $file : null;
    }

    /** Atomically write $bytes to $target (creating parent dirs), so a concurrent request never serves a half-written file. */
    private function writeFile(string $target, string $bytes, int $dirMode): bool
    {
        $dir = \dirname($target);
        if (!is_dir($dir) && !mkdir($dir, $dirMode, true) && !is_dir($dir)) {
            return false;
        }
        $tmp = $target . '.tmp.' . bin2hex(random_bytes(8)); // unique per write so concurrent forges never collide
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
     * Fetch $url. Returns [httpStatus, body]; httpStatus is 0 on a transport-level
     * failure (entry point unreachable), in which case body is false. Redirects
     * are never followed — the entry point answers a failed generation with a
     * redirect, and that non-2xx status is our negative-cache signal.
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
