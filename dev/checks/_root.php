<?php
/**
 * Resolves the Magento root for the dev check scripts, wherever the module is
 * installed (app/code/Shubo/Qshpr or vendor/shubo/module-qshpr): MAGENTO_ROOT
 * env var if set, else the nearest ancestor directory holding app/bootstrap.php,
 * else the current working directory.
 *
 * Usage: $root = require __DIR__ . '/_root.php';
 */

declare(strict_types=1);

$root = getenv('MAGENTO_ROOT') ?: null;

for ($dir = __DIR__; $root === null && $dir !== dirname($dir); $dir = dirname($dir)) {
    if (is_file($dir . '/app/bootstrap.php')) {
        $root = $dir;
    }
}

$root ??= getcwd();

if (!is_file($root . '/app/bootstrap.php')) {
    fwrite(STDERR, "Magento root not found — run from the Magento root or set MAGENTO_ROOT.\n");
    exit(2);
}

return $root;
