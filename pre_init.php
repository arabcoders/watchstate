<?php

declare(strict_types=1);

if (!defined('APP_START')) {
    define('APP_START', microtime(true));
}

if (!defined('BASE_MEMORY')) {
    define('BASE_MEMORY', memory_get_usage());
}

if (!defined('BASE_PEAK_MEMORY')) {
    define('BASE_PEAK_MEMORY', memory_get_peak_usage());
}

if (!defined('ROOT_PATH')) {
    define('ROOT_PATH', realpath(__DIR__));
}

if (!defined('STDIN')) {
    define('STDIN', fopen('php://stdin', 'rb'));
}

if (!defined('STDOUT')) {
    define('STDOUT', fopen('php://stdout', 'wb'));
}

if (!defined('STDERR')) {
    define('STDERR', fopen('php://stderr', 'wb'));
}
