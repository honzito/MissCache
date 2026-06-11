# 🪄 MissCache

> *"It only misses once."*

MissCache is a **pluggable, forge-on-miss caching library for PHP**.
Dynamic content (resized images, minified CSS/JS, generated PDFs… anything) is
produced **only when its cache file is missing**, stored on disk under a public,
source-mirroring path, and served statically on every later request.

No PHP on a cache hit. Static-file speed after the first request. ⚡

---

## ✨ Features

- 🔌 **Pluggable**: phpThumb today; Spatie/Image, JS/CSS minifiers, PDF… as plugins
- 🪄 **Lazy forge**: the artifact is created only on a **cache miss**
- 📂 **Source-mirrored paths**: cache files live under the real source directory
- 🔐 **Reversible, readable encoding**: the backend query becomes a short,
  filesystem-safe, 1:1-reversible filename. The URL carries no `?`, `&`, or any
  HTML-special character, so it is a plain static path that drops straight into
  `<img src>` without escaping
- ⚡ **Static speed**: after the first miss, the web server serves the file directly

---

## 🏗 The cache URL

```
{baseUrl}/{cacheSegment}/{routePrefix}/{srcDir}/{enc(srcName)}!{enc(params)}.{outExt}
```

A backend request like:

```
/img.php?src=/img_upload/123/photo.jpg&w=150&h=150&zc=1
```

maps to the static cache URL (here baseUrl=`/img_upload`, cacheSegment=`mC`, phpThumb prefix=`pT`):

```
/img_upload/mC/pT/123/photo.jpg!w=150!h=150!zc=1.jpg
```

- `123/photo.jpg` — the source path **relative to the base**: sources live under the
  same base as the cache (`img_upload`), so the base is mirrored once, not repeated.
  The dispatcher re-adds it when rebuilding the backend `src=/img_upload/123/photo.jpg`
  (which also keeps every `src` safely inside the base)
- `!` — the structural separator: the first `!` splits the source name from the params,
  the rest separate the params from each other (one per `&`). It can never appear inside
  an encoded segment, since a literal `!` → `~21`
- `w=150!h=150!…` — the backend params: `=` and `,` stay literal, and the `&` between
  params becomes a clean `!`
- trailing `.jpg` — the **output** extension (from the `f=` param, else `jpg`)

---

## 🔡 The encoding

Kept literal (safe in a URL path, on Linux/macOS/Windows filenames, and in HTML):
`A–Z a–z 0–9` and `- . _ = ,`. The `&` between params is not encoded at all — it is
turned into the structural `!` separator when the filename is built, so a normal query
produces no escapes. A *literal* `&` inside a name or value still gets the short escape
`&` → `~-` (so the URL stays free of `&`/`<`/`>`/`"`/`'`). Every other byte → `~HH`
(uppercase hex); `~` itself → `~7E`, and a literal `!` → `~21`. Fully reversible.

```
"w=150&h=150&zc=1"   →  w=150!h=150!zc=1     (params, via buildFilename)
"název souboru.pdf"  →  n~C3~A1zev~20souboru.pdf
```

---

## ⚙️ Installation

```
composer require honzito/misscache
```

PSR-4: `MissCache\` → `src/`. Requires PHP ≥ 8.2 and `ext-mbstring`
(`ext-curl` recommended for the phpThumb subrequest; falls back to
`allow_url_fopen`).

---

## 📖 Usage

```php
use MissCache\MissCache;
use MissCache\Plugins\PhpThumbPlugin;

$mC = new MissCache(
    '/img_upload',            // public base of the cache tree (URL path or absolute URL)
    '/srv/site/img_upload',   // its location on disk
    'mC',                     // cache sub-directory
    0775,                     // mode for created directories
    [new PhpThumbPlugin('https://example.org/img.php')] // phpThumb entry point (absolute URL)
);

// Render time — build the static cache URL (does NOT generate anything):
$url = $mC->getCachedUrl('pT', 'img_upload/123/photo.jpg?w=150&h=150&zc=1');

// Request time — in your dispatcher, on a cache miss (see public/misscache.php):
$mC->handleRequest($_SERVER['REQUEST_URI']);
```

On a miss, `PhpThumbPlugin` fetches the image from the phpThumb entry point over a
local HTTP subrequest (once per artifact) and writes it to the mirrored cache path.

---

## 🌐 Web server: route only misses to PHP

Serve existing cache files statically; route **missing** files under the cache
directory to the dispatcher (`public/misscache.php`, or — inside APC-AA —
`modules/site/site.php`, which checks the `…/mC/` prefix and calls
`handleRequest()`). The dispatcher reads `$_SERVER['REQUEST_URI']`, so nothing
extra needs to be passed.

### Nginx

```nginx
location ^~ /img_upload/mC/ {
    try_files $uri @misscache;
}
location @misscache {
    include fastcgi_params;
    fastcgi_param SCRIPT_FILENAME $document_root/misscache.php;
    fastcgi_pass php-fpm;
}
```

### Apache (`.htaccess` in the doc root)

```apache
RewriteEngine On
RewriteCond %{REQUEST_FILENAME} !-f
RewriteRule ^img_upload/mC/ /misscache.php [L]
```

---

## 🔌 Plugins

A plugin implements `MissCache\Util\PluginInterface`:

```php
public function getRoutePrefix(): string;          // e.g. "pT"
public function generate(CacheRequest $req): bool;  // write $req->filesystemPath; return success
```

- **PhpThumbPlugin** — image resizing/cropping via a phpThumb entry point ✅
- (planned) Spatie/Image, JS/CSS minify, PDF

---

## 🔐 Security notes

MissCache itself guards the write path: the request URI is rejected if it
contains `..`, a null byte, or `%` (percent-encoding never appears in a real
tilde-hex path), the decoded source name must be a pure basename, the resolved
path must stay under the cache root, and only known static-asset extensions
(`jpg, png, gif, webp, avif, bmp, ico, svg, css, js, pdf`) are ever written.

Two things are the **backend's** responsibility, not MissCache's:

- **Source path exposure.** The `src` MissCache reconstructs is handed to the
  backend (e.g. phpThumb) verbatim. If your backend allows reading files outside
  the upload tree (phpThumb's `allow_src_above_docroot=true` /
  `high_security_enabled=false`), a crafted cache URL can make it read — and then
  cache — arbitrary readable files, exactly as a direct backend request could.
  Restrict the backend to the intended source root, or enable signed URLs
  (phpThumb `high_security_enabled` + `phpThumbURL()`).
- **Cache-busting DoS.** Each distinct parameter set forges a new file. Apply
  rate limiting at the web server (nginx `limit_req`, Apache `mod_ratelimit`) and
  prune the cache periodically; the dispatcher runs unauthenticated by design.

## 📜 License

GPL-3.0-or-later © Honza Malík
