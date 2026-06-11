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
    /** Output extensions a cache artifact may have (gate against writing .php/.htaccess/... into the public cache). */
    private const ALLOWED_EXT = ['jpg', 'jpeg', 'png', 'gif', 'webp', 'avif', 'bmp', 'ico', 'svg', 'css', 'js', 'pdf'];

    private string $baseUrl;        // public base, no trailing slash, e.g. "https://x/img_upload"
    private string $basePath;       // filesystem base, no trailing slash, e.g. "/var/www/x/img_upload"
    private string $cacheSegment;   // cache subdir, no slashes, e.g. "mC"
    private int $dirMode;
    /** @var array<string, PluginInterface> indexed by route prefix */
    private array $plugins = [];
    private string $publicPrefix;   // URL path that every cache URL starts with, e.g. "/img_upload/mC/"
    private string $srcBase;        // docroot-relative base shared by cache and sources, e.g. "img_upload"

    /**
     * @param array<int, PluginInterface> $plugins
     */
    public function __construct(string $baseUrl, string $basePath, string $cacheSegment, int $dirMode, array $plugins)
    {
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

        return $this->baseUrl . '/' . $this->cacheSegment . '/' . $routePrefix
            . ($srcDir !== '' ? '/' . $srcDir : '') . '/' . $filename;
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

        if (!$plugin->generate($req) || !is_file($req->filesystemPath)) {
            http_response_code(500);
            return true;
        }

        $this->serve($req->filesystemPath, $req->outExt);
        return true;
    }

    /**
     * Evict cache artifacts (run periodically, e.g. once a day from a scheduler).
     * Delegates to {@see CachePurger} over this instance's cache root.
     *
     * @param array{maxAge?:int,maxBytes?:?int,lowWatermark?:float,tmpMaxAge?:int,dryRun?:bool} $options
     * @return array{scanned:int,deleted_age:int,deleted_size:int,deleted_tmp:int,bytes_freed:int,dirs_removed:int,total_after:int}
     */
    public function purge(array $options = []): array
    {
        return (new CachePurger($this->basePath . '/' . $this->cacheSegment))->purge($options);
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

        $dir  = \dirname($rest);
        $dir  = ($dir === '.' || $dir === DIRECTORY_SEPARATOR) ? '' : $dir;
        $file = \basename($rest);

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

    private function serve(string $path, string $ext): void
    {
        if (!headers_sent()) {
            header('Content-Type: ' . self::mimeForExt($ext));
            header('Content-Length: ' . (string) filesize($path));
        }
        readfile($path);
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
            'svg'  => 'image/svg+xml',
            'css'  => 'text/css',
            'js'   => 'application/javascript',
            'pdf'  => 'application/pdf',
            default => 'application/octet-stream',
        };
    }
}
