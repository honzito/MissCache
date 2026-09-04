<?php

namespace MissCache\Util;

/**
 * Disk-cheap eviction for a MissCache cache tree. Meant to run once a day from a
 * scheduler.
 *
 * The cache is forge-on-miss, so deleting a file is self-healing: the next
 * request regenerates it (one miss). Eviction can therefore be simple and
 * aggressive — no popularity index, no access-log parsing.
 *
 * Recency signal = max(atime, mtime): where the filesystem updates atime
 * (relatime/strictatime) this behaves as LRU; where atime is frozen (noatime) it
 * degrades to age-since-write (TTL) — no mount detection needed. The total-size
 * accounting for the optional size cap rides on the same lstat() already done for
 * recency, so the cap costs no extra I/O — only memory (a buffer of survivors) and
 * only when a cap is configured.
 *
 * One recursive walk does everything: TTL deletes stream (O(1) memory), stray
 * atomic-write temp files are reaped, and now-empty mirror directories are pruned.
 *
 * Everything the walk finds is treated as a cache artifact, with two named
 * exceptions ({@see KEEP_FILES}, {@see accept()}) — an administrator's config and
 * marker files, and version-control directories. Those exceptions are a positive
 * KEEP-list, not a positive DELETE-list, on purpose: "delete only what looks like
 * an artifact" would keep obsolete formats and retired naming schemes alive
 * forever, which is exactly what a cache purge is for.
 */
final class CachePurger
{
    /**
     * Files that are never cache artifacts: config and marker files somebody may
     * legitimately place inside the cache tree and would not expect a purge to eat.
     * (A MissCache cache root holds nothing but plugin directories, so the natural
     * home for these is that root — where the purge never even looks; this list
     * protects the ones that have to sit deeper, e.g. a per-plugin .htaccess.)
     *
     * `.DS_Store`, `Thumbs.db` and `desktop.ini` are deliberately absent: that is
     * junk, not configuration, and deleting it is the right outcome.
     */
    private const KEEP_FILES = [
        '.htaccess'    => true,  // Apache/LiteSpeed: routes misses to the dispatcher, Expires headers, Options -Indexes
        'web.config'   => true,  // the IIS equivalent
        'CACHEDIR.TAG' => true,  // Cache Directory Tagging Spec — tar --exclude-caches, borg, restic, rsnapshot
        'index.html'   => true,  // empty file guarding against a directory listing
        '.nobackup'    => true,  // ad-hoc backup-exclusion marker
        '.gitignore'   => true,  // when the tree is under version control
        'README'       => true,  // "generated automatically, do not edit / do not back up"
        'README.txt'   => true,
    ];

    public function __construct(private readonly string $cacheRoot) {}

    /**
     * @param array{maxAge?:int,maxBytes?:?int,lowWatermark?:float,tmpMaxAge?:int,dryRun?:bool} $options
     *   maxAge       delete files whose recency is older than this many seconds (default 30 days)
     *   maxBytes     hard size cap in bytes; null = no cap (default null)
     *   lowWatermark when the cap is exceeded, evict down to maxBytes*lowWatermark (default 0.9)
     *   tmpMaxAge    delete stray ".tmp.*" files older than this many seconds (default 3600)
     *   dryRun       count what would be removed without deleting anything (default false)
     * @return array{scanned:int,deleted_age:int,deleted_size:int,deleted_tmp:int,bytes_freed:int,dirs_removed:int,total_after:int}
     */
    public function purge(array $options = []): array
    {
        $maxAge       = $options['maxAge']       ?? 86400 * 30;
        $maxBytes     = $options['maxBytes']     ?? null;
        $lowWatermark = $options['lowWatermark'] ?? 0.9;
        $tmpMaxAge    = $options['tmpMaxAge']    ?? 3600;
        $dryRun       = $options['dryRun']       ?? false;

        $stats = [
            'scanned' => 0, 'deleted_age' => 0, 'deleted_size' => 0, 'deleted_tmp' => 0,
            'bytes_freed' => 0, 'dirs_removed' => 0, 'total_after' => 0,
        ];
        if (!is_dir($this->cacheRoot)) {
            return $stats;
        }

        $now       = time();
        $ageLimit  = $now - $maxAge;
        $tmpLimit  = $now - $tmpMaxAge;
        $capActive = $maxBytes !== null;

        $survivors = []; // (path, recency, size) — buffered only when a size cap is active
        $total     = 0;

        $it = new \RecursiveIteratorIterator(
            new \RecursiveCallbackFilterIterator(
                new \RecursiveDirectoryIterator($this->cacheRoot, \FilesystemIterator::SKIP_DOTS),
                static fn(\SplFileInfo $entry): bool => self::accept($entry)
            ),
            \RecursiveIteratorIterator::CHILD_FIRST // children before parent -> we can rmdir emptied dirs
        );

        foreach ($it as $entry) {
            $path = $entry->getPathname();

            if ($entry->isDir()) {
                // Prune emptied mirror dirs, but never the cache root itself (and
                // rmdir on a symlinked dir fails harmlessly — we never recurse into one).
                if (!$dryRun && rtrim($path, '/') !== rtrim($this->cacheRoot, '/') && @rmdir($path)) {
                    $stats['dirs_removed']++;
                }
                continue;
            }
            if (!$entry->isFile()) {
                continue;
            }

            $stats['scanned']++;
            // lstat, not stat: remove() deletes the link itself, never its target, so a
            // symlink must be accounted by the link (a few bytes) — charging its target's
            // size against the cap would evict real artifacts to "free" bytes we never free.
            // It also lets a broken symlink expire at all; stat() returns false on one, and
            // the `continue` below then made it immortal.
            $st = @lstat($path);
            if ($st === false) {
                continue;
            }
            $recency = max($st['atime'], $st['mtime']);
            $size    = ($st['blocks'] ?? -1) >= 0 ? $st['blocks'] * 512 : $st['size']; // actual disk usage

            // Stray atomic-write temp file left by a crashed/aborted forge. Matches
            // both the current "mc<hex>.tmp" and the legacy "<target>.tmp.<hex>" one,
            // so temp files written before the rename still get reaped.
            if (preg_match('~(?:\.tmp\.[0-9a-f]+|/mc[0-9a-f]+\.tmp)$~', $path)) {
                if ($recency < $tmpLimit && $this->remove($path, $dryRun)) {
                    $stats['deleted_tmp']++;
                    $stats['bytes_freed'] += $size;
                }
                continue;
            }

            // TTL: too old -> delete now, streaming (no buffering).
            if ($recency < $ageLimit) {
                if ($this->remove($path, $dryRun)) {
                    $stats['deleted_age']++;
                    $stats['bytes_freed'] += $size;
                }
                continue;
            }

            // Survivor: always count toward the total; buffer only if a cap might evict it.
            $total += $size;
            if ($capActive) {
                $survivors[] = ['path' => $path, 'recency' => $recency, 'size' => $size];
            }
        }

        // Size cap: evict oldest-first (LRU) until under the low watermark.
        if ($capActive && $total > $maxBytes) {
            $target = (int) ($maxBytes * $lowWatermark);
            usort($survivors, static fn(array $a, array $b): int => $a['recency'] <=> $b['recency']);
            foreach ($survivors as $f) {
                if ($total <= $target) {
                    break;
                }
                if ($this->remove($f['path'], $dryRun)) {
                    $stats['deleted_size']++;
                    $stats['bytes_freed'] += $f['size'];
                    $total -= $f['size'];
                }
            }
        }

        $stats['total_after'] = $total;
        return $stats;
    }

    /**
     * What the walk is allowed to see.
     *
     * A directory whose name starts with a dot (.svn, .git, ...) is pruned: never
     * descended into, never rmdir'd. It holds no cache, and deleting its contents
     * would corrupt a working copy.
     *
     * A file on {@see KEEP_FILES} is invisible to the walk, so it is never deleted
     * — and never counted in the statistics either, which is right: it is not
     * cache, and its handful of bytes should not push against the size cap.
     */
    private static function accept(\SplFileInfo $entry): bool
    {
        $name = $entry->getFilename();

        if ($entry->isDir()) {
            return !str_starts_with($name, '.');
        }
        return !isset(self::KEEP_FILES[$name]);
    }

    /**
     * Delete $path (unless $dryRun). Returns true when it counts as removed.
     * unlink() on a symlink removes the link itself, not its target, so a stray
     * symlink in the cache can never make us delete a file outside the tree.
     */
    private function remove(string $path, bool $dryRun): bool
    {
        return $dryRun ? true : @unlink($path);
    }
}
