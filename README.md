# 🪄 MissCache

> *"It only misses once."*

MissCache is a **pluggable, forge-on-miss caching library for PHP**.  
Dynamic content (images, CSS/JS, PDFs… anything) is generated **only when a cache file is missing**, stored on disk, and served statically next time.

No runtime overhead on hits. Super fast after the first request. ⚡

---

## ✨ Features

- 🔌 **Pluggable**: phpThumb, Spatie/Image, JS/CSS minifiers… anything can be a plugin
- 🪄 **Lazy forge**: content is created only on a **cache miss**
- 📂 **Filesystem-mirrored paths**: cached files live under real `src` directories
- 🔐 **Safe & reversible encoding**: query strings are encoded with *tilde-hex* → filenames are short, safe, and 1:1 reversible
- ⚡ **Static speed**: after the first miss, files are served directly by Nginx/Apache

---

## 🚀 How it works

1. First request hits your dynamic backend (`phpThumb`, Spatie, etc).
2. MissCache:
    - Parses the cache filename,
    - Reconstructs the **exact raw query string**,
    - Runs the plugin backend,
    - Saves the generated file under the same path.
3. Next time → Nginx/Apache serves the file directly, **no PHP needed**.

---

## 🏗 Example

Dynamic request:

  /lib/phpThumb.php?src=/upload/xy/myimage.jpg&w=600&h=338&zc=1&fltr[]=gray&fltr[]=brightness,10

Cached (static) path:

  /MissCache/phpThumb/upload/xy/myimage!w=60026h=33826zc=126fltr%5B%5D=gray26fltr%5B%5D=brightness,10.jpg

- `myimage` → tilde-hex encoded base name  
- everything after `src=` → tilde-hex encoded querystring  

---

## 🔌 Plugins

MissCache is pluggable. Current adapters:

- **PhpThumbPlugin** → classic image resizing/cropping  
- (planned) **SpatieImagePlugin** → Spatie/Image backend  
- (planned) **JsMinifyPlugin** → JS minification
- (planned) **CSSMinifyPlugin** → CSS minification
- (planned) **PDFLibPlugin** → PDF generation


---

## ⚙️ Installation

composer require yourname/misscache

### Nginx

```
location ^~ /phpThumbCache/ {
    try_files $uri @misscache;
}

location @misscache {
    fastcgi_param SCRIPT_FILENAME /var/www/app/public/imggen.php;
    fastcgi_param QUERY_STRING fpath=$uri;
    include fastcgi_params;
    fastcgi_pass php-fpm;
}
```

### Apache
```
RewriteEngine On
RewriteCond %{REQUEST_FILENAME} !-f
RewriteRule ^(phpThumbCache|spatieCache)/(.+)$ public/imggen.php?fpath=/$1/$2 [L,QSA]
```

## 🔡 Tilde-hex encoding
MissCache uses a compact encoding for safe, readable filenames:

Allowed: A–Z a–z 0–9 - _ .

Everything else → ~HH (hex byte)

Example:

"název souboru.pdf" → n~C3~A1zev~20souboru.pdf

"foo~bar&baz" → foo~7Ebar~26baz

It’s fully reversible: filenames map back 1:1 to querystrings.

## 📖 Usage
Drop public/MissCache.php in your project (provided in /public).

Configure Nginx/Apache as above.

Add plugins for your backends (phpThumb, Spatie…).

First request generates → all others are served statically.

## 🛠 Development
PSR-4 autoload namespace: MissCache\\ → src/

Classes are organized under:


## 📜 License
GPLv3 or later @ Honza Malík
