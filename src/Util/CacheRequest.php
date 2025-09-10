<?php

namespace MissCache\Util;

final class CacheRequest
{
    public function __construct(
        public readonly string $dir,    // e.g. "img_upload/xy"
        public readonly string $name,   // decoded original basename (UTF-8)
        public readonly string $ext,    // output extension (from filename)
        public readonly ?string $qs     // decoded raw "k=v&k=v..." (may be null/empty)
    ) {}

    /** Reconstruct the exact raw query string for the backend. */
    public function toRawQueryString(bool $leadingSlashInSrc = true): string
    {
        $src = ($leadingSlashInSrc ? '/' : '') .
            ($this->srcDirRel !== '' ? $this->srcDirRel . '/' : '') .
            $this->baseName . '.' . $this->ext;

        $qs = 'src=' . $src;
        if ($this->restRaw !== null && $this->restRaw !== '') {
            $qs .= '&' . $this->restRaw;
        }
        return $qs;
    }

    /** Build from a route remainder like "img_upload/xy/dscn85!ENCREST.jpg" */
    public static function fromRouteRemainder(string $routeRemainder): self
    {
        // Split path into dir + filename
        $routeRemainder = str_replace('\\', '/', $routeRemainder);
        $dir = \dirname($routeRemainder);
        if ($dir === '.' || $dir === DIRECTORY_SEPARATOR) $dir = '';

        $file = \basename($routeRemainder);
        $dot = strrpos($file, '.');
        if ($dot === false) {
            throw new \RuntimeException('Invalid cache filename: missing extension');
        }
        $nameNoExt = substr($file, 0, $dot);
        $ext       = substr($file, $dot + 1);

        // Split "<encBase>!<encRest>" or just "<encBase>"
        $bang = strpos($nameNoExt, '!');
        if ($bang === false) {
            $encBase = $nameNoExt;
            $encRest = null;
        } else {
            $encBase = substr($nameNoExt, 0, $bang);
            $encRest = substr($nameNoExt, $bang + 1);
        }

        $base = self::decode($encBase);
        $rest = $encRest !== null ? self::decode($encRest) : null;

        return new self($dir === '.' ? '' : $dir, $base, $ext, $rest);
    }

    /** For completeness: build the cache filename from components */
    public static function buildFilename(string $baseName, ?string $restRaw, string $ext): string
    {
        $encBase = self::encode($baseName);
        return $encBase . ($restRaw ? '!' . self::encode($restRaw) : '') . '.' . $ext;
    }
    
    
    public static function encode(string $s): string {
        $out = '';
        $bytes = mb_convert_encoding($s, 'UTF-8', 'UTF-8');
        $len = strlen($bytes);
        for ($i = 0; $i < $len; $i++) {
            $b = ord($bytes[$i]);
            $ch = $bytes[$i];
            $safe = ($b >= 0x30 && $b <= 0x39) ||
                    ($b >= 0x41 && $b <= 0x5A) ||
                    ($b >= 0x61 && $b <= 0x7A) ||
                    $b === 0x2E || $b === 0x5F || $b === 0x2D;
            if ($safe && $b !== 0x7E) {
                $out .= $ch;
            } else {
                $out .= '~' . strtoupper(str_pad(dechex($b), 2, '0', STR_PAD_LEFT));
            }
        }
        return $out;
    }

    public static function decode(string $t): string {
        $bin = '';
        for ($i = 0, $iMax = strlen($t); $i < $iMax; ) {
            if ($t[$i] === '~') {
                $hh = substr($t, $i + 1, 2);
                if (!preg_match('/^[0-9A-F]{2}$/', $hh)) {
                    throw new \RuntimeException("Bad escape at $i");
                }
                $bin .= chr(hexdec($hh));
                $i += 3;
            } else {
                $bin .= $t[$i++];
            }
        }
        return mb_convert_encoding($bin, 'UTF-8', 'UTF-8');
    }
}