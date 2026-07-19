<?php

declare(strict_types=1);

spl_autoload_register(
    static function (string $class): void {
        $prefix = 'PolyFeeds\\';
        $baseDirectory = __DIR__ . '/src/';

        if (!str_starts_with($class, $prefix)) {
            return;
        }

        $relativeClass = substr($class, strlen($prefix));
        $file = $baseDirectory
            . str_replace('\\', '/', $relativeClass)
            . '.php';

        if (is_file($file)) {
            require $file;
        }
    }
);
