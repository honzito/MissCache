<?php

/*
 * Test bootstrap.
 *
 * The library has no committed vendor/ of its own; when developed inside
 * APC-AA it is symlinked into apc-aa/vendor, so the host autoloader already
 * resolves the MissCache\ namespace. We reuse it and register only the test
 * namespace here. If a standalone vendor/ exists, that is used instead.
 */

declare(strict_types=1);

$standalone = __DIR__ . '/../vendor/autoload.php';
$host       = __DIR__ . '/../../../vendor/autoload.php'; // apc-aa/vendor/autoload.php

if (is_file($standalone)) {
    require $standalone;
} elseif (is_file($host)) {
    require $host;
} else {
    fwrite(STDERR, "No autoloader found (run composer install).\n");
    exit(1);
}

spl_autoload_register(static function (string $class): void {
    $prefix = 'MissCache\\Tests\\';
    if (str_starts_with($class, $prefix)) {
        $file = __DIR__ . '/' . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';
        if (is_file($file)) {
            require $file;
        }
    }
});
