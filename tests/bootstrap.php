<?php

/*
 * Test bootstrap.
 *
 * The library has no committed vendor/ of its own. When developed inside APC-AA,
 * composer mirrors it into apc-aa/vendor/honzito/misscache as a COPY (the path
 * repository sets "symlink": false, so the tree stays SVN-friendly). We therefore
 * borrow the host autoloader for PHPUnit and friends, but must NOT let it resolve
 * MissCache\ itself: it would point at that copy, and the suite would silently
 * test the last mirrored release instead of the working tree being edited.
 *
 * So MissCache\ is bound to ../src FIRST (prepended, before composer's autoloader
 * is even registered). A standalone vendor/, if present, is a plain composer
 * install of this library and maps the same namespace to the same src/.
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

// Working tree wins over any mirrored copy. Registered AFTER the autoloader above and
// prepended: composer registers itself with prepend=true, so a loader added before it
// would be pushed to second place and the mirrored copy would win again.
spl_autoload_register(static function (string $class): void {
    $prefix = 'MissCache\\';
    if (!str_starts_with($class, $prefix) || str_starts_with($class, 'MissCache\\Tests\\')) {
        return;
    }
    $file = __DIR__ . '/../src/' . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';
    if (is_file($file)) {
        require $file;
    }
}, prepend: true);

spl_autoload_register(static function (string $class): void {
    $prefix = 'MissCache\\Tests\\';
    if (str_starts_with($class, $prefix)) {
        $file = __DIR__ . '/' . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';
        if (is_file($file)) {
            require $file;
        }
    }
});
