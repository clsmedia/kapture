<?php

spl_autoload_register(function (string $class): void {
    $prefix = 'App\\';
    $base = __DIR__ . '/src/';
    if (str_starts_with($class, $prefix)) {
        $file = $base . str_replace('\\', '/', substr($class, 4)) . '.php';
        if (file_exists($file)) {
            require $file;
        }
    }
});
