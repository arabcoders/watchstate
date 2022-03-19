<?php

declare(strict_types=1);

use Psr\Http\Message\ServerRequestInterface;

error_reporting(E_ALL);
ini_set('error_reporting', 'On');
ini_set('display_errors', 'Off');

require __DIR__ . '/../pre_init.php';

set_error_handler(function (int $number, mixed $error, mixed $file, int $line) {
    $errno = $number & error_reporting();
    if (0 === $errno) {
        return;
    }

    syslog(LOG_ERR, trim(sprintf('%s: %s (%s:%d)', $number, $error, $file, $line)));

    exit(1);
});

set_exception_handler(function (Throwable $e) {
    syslog(LOG_ERR, trim(sprintf("%s: %s (%s:%d).", get_class($e), $e->getMessage(), $e->getFile(), $e->getLine())));
    exit(1);
});

if (!file_exists(__DIR__ . '/../vendor/autoload.php')) {
    echo 'App is not initialized dependencies are missing. Please refer to docs.';
    exit(1);
}

require __DIR__ . '/../vendor/autoload.php';

(new App\Libs\Initializer())->boot()->runHttp(
    function (ServerRequestInterface $request) {
        return serveHttpRequest($request);
    }
);
