<?php

declare(strict_types=1);

use App\Command;
use App\Libs\Emitter;
use App\Libs\HTTP_STATUS;

error_reporting(E_ALL);
ini_set('error_reporting', 'On');
ini_set('display_errors', 'Off');

require __DIR__ . '/../pre_init.php';

if (!file_exists(__DIR__ . '/../vendor/autoload.php')) {
    print 'Dependencies are missing please refer to https://github.com/arabcoders/watchstate/blob/master/FAQ.md';
    exit(Command::FAILURE);
}

require __DIR__ . '/../vendor/autoload.php';

/**
 * Throws an exception based on an error code.
 *
 * @param int $number The error code.
 * @param mixed $error The error message.
 * @param mixed $file The file where the error occurred.
 * @param int $line The line number where the error occurred.
 *
 * @throws ErrorException When the error code is not suppressed by error_reporting.
 */
$errorHandler = function (int $number, mixed $error, mixed $file, int $line) {
    $errno = $number & error_reporting();
    if (0 === $errno) {
        return;
    }

    throw new ErrorException($error, $number, 1, $file, $line);
};

set_error_handler($errorHandler);

set_exception_handler(function (Throwable $e) {
    $out = fn($message) => inContainer() ? fwrite(STDERR, $message) : syslog(LOG_ERR, $message);
    $out(r(text: '{kind}: {message} ({file}:{line}).', context: [
        'kind' => $e::class,
        'line' => $e->getLine(),
        'message' => $e->getMessage(),
        'file' => after($e->getFile(), ROOT_PATH),
    ]));
    exit(Command::FAILURE);
});

try {
    // -- In case the frontend proxy does not generate request unique id.
    if (!isset($_SERVER['X_REQUEST_ID'])) {
        $_SERVER['X_REQUEST_ID'] = bin2hex(random_bytes(16));
    }

    $app = (new App\Libs\Initializer())->boot();
} catch (Throwable $e) {
    $out = fn($message) => inContainer() ? fwrite(STDERR, $message) : syslog(LOG_ERR, $message);

    $out(
        r(
            text: "HTTP: Exception '{kind}' was thrown unhandled during HTTP boot context. Error '{message} @ {file}:{line}'.",
            context: [
                'kind' => $e::class,
                'line' => $e->getLine(),
                'message' => $e->getMessage(),
                'file' => after($e->getFile(), ROOT_PATH),
            ]
        )
    );

    if (!headers_sent()) {
        http_response_code(HTTP_STATUS::HTTP_SERVICE_UNAVAILABLE->value);
    }

    exit(Command::FAILURE);
}

try {
    (new Emitter())($app->http());
} catch (Throwable $e) {
    $out = fn($message) => inContainer() ? fwrite(STDERR, $message) : syslog(LOG_ERR, $message);

    $out(
        r(
            text: "HTTP: Exception '{kind}' was thrown unhandled during response context. Error '{message} @ {file}:{line}'.",
            context: [
                'kind' => $e::class,
                'line' => $e->getLine(),
                'message' => $e->getMessage(),
                'file' => after($e->getFile(), ROOT_PATH),
            ]
        )
    );

    if (!headers_sent()) {
        http_response_code(HTTP_STATUS::HTTP_SERVICE_UNAVAILABLE->value);
    }

    exit(Command::FAILURE);
}

