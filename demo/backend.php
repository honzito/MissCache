<?php

/*
 * Tiny phpThumb stand-in for the demo: resizes ?src= to ?w=&h= with GD.
 * Run:  php -S 127.0.0.1:8077 backend.php   (from this demo/ directory)
 */

$docroot = __DIR__ . '/cache_root';
$src     = (string) ($_GET['src'] ?? '');
$w       = max(1, (int) ($_GET['w'] ?? 100));
$h       = max(1, (int) ($_GET['h'] ?? 100));

$path = $docroot . $src; // $src is like /img_upload/123/photo.jpg
if ($src === '' || !is_file($path)) {
    http_response_code(404);
    echo 'no such source';
    return;
}

$srcImg = imagecreatefromjpeg($path);
$dst    = imagecreatetruecolor($w, $h);
imagecopyresampled($dst, $srcImg, 0, 0, 0, 0, $w, $h, imagesx($srcImg), imagesy($srcImg));

header('Content-Type: image/jpeg');
imagejpeg($dst, null, 90);
