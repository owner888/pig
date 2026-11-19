<?php

declare(strict_types=1);

spl_autoload_register(static function (string $class): void {
    if (str_starts_with($class, 'Accounts\\')) {
        require __DIR__ . '/' . substr($class, strlen('Accounts\\')) . '.php';
    }
});
