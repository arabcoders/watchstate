<?php

declare(strict_types=1);

if (!defined('BASE_MEMORY')) {
    define('BASE_MEMORY', memory_get_usage());
}

if (!defined('BASE_PEAK_MEMORY')) {
    define('BASE_PEAK_MEMORY', memory_get_peak_usage());
}

if (!defined('ROOT_PATH')) {
    define('ROOT_PATH', realpath(__DIR__));
}
