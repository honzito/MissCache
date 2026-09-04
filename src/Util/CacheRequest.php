<?php

namespace MissCache\Util;

/**
 * Immutable description of one cache request, plus the reversible tilde-hex
 * codec that maps a backend query string to/from a filesystem-safe cache
 * filename.
 *
 * Cache filename layout (within the mirrored source directory):
 *
 *     <enc(srcName)>!<enc(param1)>!<enc(param2)>!...!<enc(paramN)>.<outExt>
 *
 * where srcName is the original source basename (incl. its extension), each
 * paramK is one "&"-separated backend query pair (without "src="), and outExt is
 * the extension of the generated artifact (jpg/avif/webp/...). "!" is the
 * structural separator: the first "!" splits srcName from the params, the rest
 * separate the params from each other. It can never appear inside an encoded
 * segment because "!" (0x21) is itself escaped to "~21" (and "&" to "~-"), so the
 * split is unambiguous.
 */
final class CacheRequest
{
    public function __construct(
        public readonly string $routePrefix,    // plugin route prefix, e.g. "pT"
        public readonly string $srcDir,         // source dir relative to srcBase, e.g. "123" (no slashes around)
        public readonly string $srcName,        // decoded source basename incl. original ext, e.g. "photo.jpg"
        public readonly ?string $params,        // decoded raw "k=v&k=v..." (without "src="); null/empty if none
        public readonly string $outExt,         // generated-artifact extension, e.g. "jpg"
        public readonly string $filesystemPath, // absolute path where the artifact must be written
        public readonly int $dirMode = 0775,    // mode for directories created on the way to filesystemPath
        public readonly string $srcBase = '',   // docroot-relative base shared by cache+sources (e.g. "img_upload"), re-added to rebuild src
        public readonly ?string $sourceFsPath = null // absolute path of the source file on disk, when resolvable; lets a plugin short-circuit a known-missing source
    ) {}

    /** Reconstruct the exact raw query string for the backend (e.g. "src=/img_upload/123/photo.jpg&w=150&h=150"). */
    public function toRawQueryString(bool $leadingSlashInSrc = true): string
    {
        $src = ($leadingSlashInSrc ? '/' : '')
            . ($this->srcBase !== '' ? $this->srcBase . '/' : '')
            . ($this->srcDir !== '' ? $this->srcDir . '/' : '')
            . $this->srcName;

        $qs = 'src=' . $src;
        if ($this->params !== null && $this->params !== '') {
            $qs .= '&' . $this->params;
        }
        return $qs;
    }

    /** Build the cache filename from its components (forward direction). */
    public static function buildFilename(string $srcName, ?string $params, string $outExt): string
    {
        $enc = self::encode($srcName);
        if ($params !== null && $params !== '') {
            // Encode each "&"-separated param independently and join them with "!".
            // "!" is purely structural: encode() escapes any literal "!" to "~21"
            // and any literal "&" to "~-", so neither can appear raw inside a segment.
            $enc .= '!' . implode('!', array_map(self::encode(...), explode('&', $params)));
        }
        return $enc . '.' . $outExt;
    }

    /**
     * Parse a cache filename back into [srcName, params, outExt] (reverse direction).
     *
     * @return array{0:string,1:?string,2:string}
     */
    public static function parseFilename(string $filename): array
    {
        $dot = strrpos($filename, '.');
        if ($dot === false || $dot === 0) {
            throw new \RuntimeException('Invalid cache filename: missing extension');
        }
        $outExt    = substr($filename, $dot + 1);
        $nameNoExt = substr($filename, 0, $dot);

        $bang = strpos($nameNoExt, '!');
        if ($bang === false) {
            $srcName = self::decode($nameNoExt);
            $params  = null;
        } else {
            // First "!" splits srcName from the params; the rest separate the
            // params (joined with "!" by buildFilename) — decode each, rejoin "&".
            $srcName = self::decode(substr($nameNoExt, 0, $bang));
            $params  = implode('&', array_map(self::decode(...), explode('!', substr($nameNoExt, $bang + 1))));
        }

        return [$srcName, $params, $outExt];
    }

    /**
     * Split a cache filename into path segments that each fit within $maxSegment
     * bytes, or return it whole when it already does.
     *
     * NAME_MAX caps ONE path component (255 bytes on ext4/xfs/btrfs, 143 on
     * eCryptfs); PATH_MAX caps the whole path at 4096. Spreading a long name over
     * several components is therefore the only way to address it at all — and the
     * web server, not just the filesystem, enforces this: measured on
     * actionapps.org, a cache URL whose last segment is 260 bytes is answered 403
     * by Apache, whose rewrite stat()s the segment, gets ENAMETOOLONG and never
     * reaches PHP. Nothing PHP-side can rescue such a URL.
     *
     * The segments come out as equal in length as the total allows (differing by at
     * most one byte), so a long name never ends in a one-character directory, and
     * the ORIGINAL EXTENSION always rides on the last one — the static server types
     * its response from that segment on every hit that never touches PHP.
     *
     * Reversible by concatenation: {@see joinFilename()}.
     *
     * @return list<string> one element when no split is needed
     */
    public static function splitFilename(string $filename, int $maxSegment): array
    {
        $total = strlen($filename);
        if ($total <= $maxSegment) {
            return [$filename];
        }

        $dot       = strrpos($filename, '.');
        $extension = $dot === false ? '' : substr($filename, $dot);
        $body      = $dot === false ? $filename : substr($filename, 0, $dot);

        $count = intdiv($total + $maxSegment - 1, $maxSegment);   // ceil: fewest segments that fit
        // Spread the WHOLE length (extension included) evenly, so the last segment —
        // the one carrying the extension — comes out the same size as the others
        // rather than that much longer.
        $even      = intdiv($total, $count);
        $remainder = $total % $count;

        if ($even < strlen($extension)) {
            throw new \RuntimeException('maxSegment too small for the extension');
        }

        $chunks = [];
        $offset = 0;
        for ($i = 0; $i < $count; $i++) {
            $length = $even + ($i < $remainder ? 1 : 0);
            if ($i === $count - 1) {
                $length -= strlen($extension);   // the extension is appended below
            }
            $chunks[] = substr($body, $offset, $length);
            $offset  += $length;
        }
        $chunks[$count - 1] .= $extension;

        return $chunks;
    }

    /** Reverse of {@see splitFilename()}. @param list<string> $chunks */
    public static function joinFilename(array $chunks): string
    {
        return implode('', $chunks);
    }

    /**
     * Encode a string into a short, readable, filesystem- and URL-path-safe form.
     *
     * Kept literal (legal in a URL path segment, on Linux/macOS/Windows filenames,
     * and needs no HTML escaping): 0-9 A-Z a-z and `- . _ = ,`.
     * Short escape: `&` -> `~-` (escaped, not left literal, so the resulting URL
     * carries no `&`/`<`/`>`/`"`/`'` and drops straight into HTML `<img src>`).
     * Everything else (incl. `/ ~ ! % [ ] | space` and all non-ASCII) -> `~HH`.
     * `~` itself -> `~7E`. The escape codes (`-` and the hex digits) are distinct,
     * so decoding is unambiguous.
     */
    public static function encode(string $s): string
    {
        $out = '';
        $len = strlen($s);
        for ($i = 0; $i < $len; $i++) {
            $b = ord($s[$i]);
            if ($b === 0x26) {           // & -> ~-  (kept escaped for HTML cleanliness)
                $out .= '~-';
                continue;
            }
            $safe = ($b >= 0x30 && $b <= 0x39)   // 0-9
                 || ($b >= 0x41 && $b <= 0x5A)   // A-Z
                 || ($b >= 0x61 && $b <= 0x7A)   // a-z
                 || $b === 0x2E || $b === 0x5F || $b === 0x2D   // . _ -
                 || $b === 0x3D || $b === 0x2C;                 // = ,
            if ($safe) {
                $out .= $s[$i];
            } else {
                $out .= '~' . strtoupper(str_pad(dechex($b), 2, '0', STR_PAD_LEFT));
            }
        }
        return $out;
    }

    /** Reverse of {@see encode()}. Throws on a malformed escape sequence. */
    public static function decode(string $t): string
    {
        $out = '';
        for ($i = 0, $iMax = strlen($t); $i < $iMax;) {
            if ($t[$i] === '~') {
                if (($t[$i + 1] ?? '') === '-') {   // ~- -> &
                    $out .= '&';
                    $i += 2;
                    continue;
                }
                $hh = substr($t, $i + 1, 2);
                if (!preg_match('/^[0-9A-Fa-f]{2}$/', $hh)) {
                    throw new \RuntimeException("Bad escape at offset $i");
                }
                $out .= chr(hexdec($hh));
                $i += 3;
            } else {
                $out .= $t[$i++];
            }
        }
        return $out;
    }
}
