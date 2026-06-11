<?php

/*
 * Working MissCache demo — the full forge-on-miss cycle, end to end.
 *
 *   Terminal 1:  cd demo && php -S 127.0.0.1:8077 backend.php
 *   Terminal 2:  php demo.php
 *
 * (or just run ./run.sh which does both)
 */

declare(strict_types=1);

foreach ([__DIR__ . '/../vendor/autoload.php', __DIR__ . '/../../../vendor/autoload.php'] as $a) {
    if (is_file($a)) { require $a; break; }
}

use MissCache\MissCache;
use MissCache\Plugins\PhpThumbPlugin;

$docroot = __DIR__ . '/cache_root';

// --- make sure a source image exists (800x600 gradient) -----------------------
if (!mkdir("$docroot/upload/123", 0775, true) && !is_dir("$docroot/upload/123")) {
    throw new \RuntimeException(sprintf('Directory "%s" was not created', "$docroot/upload/123"));
}
$srcImg = "$docroot/img_upload/123/photo.jpg";
if (!is_file($srcImg)) {
    $im = imagecreatetruecolor(800, 600);
    for ($y = 0; $y < 600; $y++) {
        $c = imagecolorallocate($im, (int) ($y / 600 * 255), 80, 200 - (int) ($y / 600 * 160));
        imageline($im, 0, $y, 800, $y, $c);
    }
    imagejpeg($im, $srcImg, 90);
}

$mC = new MissCache('/img_upload', "$docroot/img_upload", 'mC', 0775, [
    new PhpThumbPlugin('http://127.0.0.1:8077/backend.php'),
]);

echo "Source image: img_upload/123/photo.jpg (" . implode('x', array_slice(getimagesize($srcImg), 0, 2)) . ")\n\n";

// 1) render time — build the static cache URL ---------------------------------
$url     = $mC->getCachedUrl('pT', 'img_upload/123/photo.jpg?w=200&h=150');
$reqPath = parse_url($url, PHP_URL_PATH);
$fsPath  = $docroot . $reqPath;
echo "1) getCachedUrl() -> static path (no ? & =):\n   $url\n\n";

// 2) miss ---------------------------------------------------------------------
@array_map('unlink', glob("$docroot/img_upload/mC/pT/123/*") ?: []); // base is stripped, so no second img_upload
echo "2) file on disk before first request? " . (is_file($fsPath) ? 'yes' : 'NO  -> cache MISS') . "\n\n";

// 3) first request -> forge on miss -------------------------------------------
echo "3) first request -> handleRequest() forges it via the backend:\n";
ob_start();
$mC->handleRequest($reqPath);
$served = ob_get_clean();
$info   = getimagesizefromstring($served);
echo "   served " . strlen($served) . " bytes, " . ($info ? "valid {$info[0]}x{$info[1]} " . image_type_to_mime_type($info[2]) : 'NOT an image') . "\n";
echo "   forged file now exists? " . (is_file($fsPath) ? 'YES' : 'no') . "\n";
echo "   on disk: " . str_replace($docroot, '<docroot>', $fsPath) . "\n\n";

// 4) hit ----------------------------------------------------------------------
echo "4) every later request is served by the web server as a static file (no PHP).\n";
echo "   static bytes == forged bytes: " . (file_get_contents($fsPath) === $served ? 'yes' : 'no') . "\n\n";

// 5) security -----------------------------------------------------------------
echo "5) path traversal is refused before any write:\n";
try {
    $mC->parseRequest('/img_upload/mC/pT/../../../etc/passwd.jpg');
    echo "   NOT rejected (BUG)\n";
} catch (\RuntimeException $e) {
    echo "   rejected: {$e->getMessage()}\n";
}
