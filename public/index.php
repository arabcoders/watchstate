<?php

declare(strict_types=1);

use Psr\Http\Message\ServerRequestInterface;

error_reporting(E_ALL);
ini_set('error_reporting', 'On');
ini_set('display_errors', 'Off');

require __DIR__ . '/../pre_init.php';

if (!file_exists(__DIR__ . '/../vendor/autoload.php')) {
    print 'Tool is not yet. Composer dependencies is not installed.';
    exit(1);
}

require __DIR__ . '/../vendor/autoload.php';

set_error_handler(function (int $number, mixed $error, mixed $file, int $line) {
    $errno = $number & error_reporting();
    if (0 === $errno) {
        return;
    }

    $message = trim(sprintf('%s: %s (%s:%d)', $number, $error, $file, $line));

    if (env('IN_DOCKER')) {
        fwrite(STDERR, $message);
    } else {
        syslog(LOG_ERR, $message);
    }

    exit(1);
});

set_exception_handler(function (Throwable $e) {
    $message = trim(sprintf("%s: %s (%s:%d).", get_class($e), $e->getMessage(), $e->getFile(), $e->getLine()));

    if (env('IN_DOCKER')) {
        fwrite(STDERR, $message);
    } else {
        syslog(LOG_ERR, $message);
    }

    exit(1);
});

(new App\Libs\Initializer())->boot()->runHttp(fn(ServerRequestInterface $request) => serveHttpRequest($request));
