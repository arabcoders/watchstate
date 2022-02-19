<?php

declare(strict_types=1);

require __DIR__ . '/../pre_init.php';

if (!file_exists(__DIR__ . '/../vendor/autoload.php')) {
    fwrite(STDERR, 'Composer dependencies are missing. Run the following commands.' . PHP_EOL);
    fwrite(STDERR, sprintf('cd %s', dirname(__DIR__)) . PHP_EOL);
    fwrite(STDERR, 'composer install --optimize-autoloader' . PHP_EOL);
    exit(1);
}

require __DIR__ . '/../vendor/autoload.php';

