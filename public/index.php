<?php

declare(strict_types=1);

use App\Command;
use App\Libs\Emitter;
use App\Libs\Enums\Http\Status;
use App\Libs\Profiler;
use App\Libs\Uri;
use App\Listeners\ProcessProfileEvent;
use Nyholm\Psr7\Factory\Psr17Factory;
use Nyholm\Psr7Server\ServerRequestCreator;

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
    $out = fn($message) => in_container() ? fwrite(STDERR, $message) : syslog(LOG_ERR, $message);
    $out(r(text: '{kind}: {message} ({file}:{line}).', context: [
        'kind' => $e::class,
        'line' => $e->getLine(),
        'message' => $e->getMessage(),
        'file' => after($e->getFile(), ROOT_PATH),
    ]));
    exit(Command::FAILURE);
});

$factory = new Psr17Factory();
if (!isset($_SERVER['X_REQUEST_ID'])) {
    $_SERVER['X_REQUEST_ID'] = generate_uuid();
}
$request = new ServerRequestCreator($factory, $factory, $factory, $factory)->fromGlobals();
$profiler = new Profiler(callback: function (array $data) {
    $filter = function (array $data): array {
        $data['env'] = [];

        $maskKeys = [
            'meta.SERVER.HTTP_USER_AGENT' => true,
            'meta.SERVER.PHP_AUTH_USER' => true,
            'meta.SERVER.REMOTE_USER' => true,
            'meta.SERVER.UNIQUE_ID' => true,
            'meta.get.apikey' => true,
            'meta.get.' . Profiler::QUERY_NAME => false,
        ];

        foreach ($maskKeys as $key => $mask) {
            if (false === ag_exists($data, $key)) {
                continue;
            }

            if (true === $mask) {
                $data = ag_set($data, $key, '__masked__');
                continue;
            }

            $data = ag_delete($data, $key);
        }

        if ('CLI' !== ag($data, 'meta.SERVER.REQUEST_METHOD')) {
            try {
                if (null !== ($query = ag($data, 'meta.url'))) {
                    $url = new Uri($query);
                    $query = $url->getQuery();
                    if (!empty($query)) {
                        $parsed = [];
                        parse_str($query, $parsed);
                        foreach ($maskKeys as $key => $mask) {
                            if (false === str_starts_with($key, 'meta.get.')) {
                                continue;
                            }

                            $key = substr($key, 9);

                            if (false === ag_exists($parsed, $key)) {
                                continue;
                            }

                            if (true === $mask) {
                                $parsed = ag_set($parsed, $key, '__masked__');
                                continue;
                            }

                            $parsed = ag_delete($parsed, $key);
                        }
                        $data = ag_set($data, 'meta.url', (string)$url->withQuery(http_build_query($parsed)));
                    }
                }
            } catch (Throwable) {
            }

            try {
                if (null !== ($url = ag($data, 'meta.simple_url'))) {
                    $url = new Uri($url)->withQuery('');
                    $data = ag_set($data, 'meta.simple_url', (string)$url);
                }
            } catch (Throwable) {
            }

            $queryString = ag($data, 'meta.SERVER.QUERY_STRING');
            if (!empty($queryString)) {
                $parsed = [];
                parse_str($queryString, $parsed);
                foreach ($maskKeys as $key => $mask) {
                    if (false === str_starts_with($key, 'meta.get.')) {
                        continue;
                    }

                    $key = substr($key, 9);

                    if (false === ag_exists($parsed, $key)) {
                        continue;
                    }

                    if (true === $mask) {
                        $parsed = ag_set($parsed, $key, '__masked__');
                        continue;
                    }

                    $parsed = ag_delete($parsed, $key);
                }
                $data = ag_set($data, 'meta.SERVER.QUERY_STRING', http_build_query($parsed));
            }
        }

        return $data;
    };
    queue_event(ProcessProfileEvent::NAME, $filter($data));
});

$exitCode = $profiler->process(function () use ($request) {
    try {
        // -- In case the frontend proxy does not generate request unique id.
        if (!isset($_SERVER['X_REQUEST_ID'])) {
            $_SERVER['X_REQUEST_ID'] = generate_uuid();
        }

        $app = new App\Libs\Initializer()->boot();
    } catch (Throwable $e) {
        $out = fn($message) => in_container() ? fwrite(STDERR, $message) : syslog(LOG_ERR, $message);

        $out(
            r(
                text: "HTTP: Exception '{kind}' was thrown unhandled during HTTP boot context. {message} at {file}:{line}.",
                context: [
                    'kind' => $e::class,
                    'line' => $e->getLine(),
                    'message' => $e->getMessage(),
                    'file' => $e->getFile(),
                ]
            )
        );

        if (!headers_sent()) {
            http_response_code(Status::SERVICE_UNAVAILABLE->value);
        }

        return Command::FAILURE;
    }

    try {
        new Emitter()($app->http($request));
    } catch (Throwable $e) {
        $out = fn($message) => in_container() ? fwrite(STDERR, $message) : syslog(LOG_ERR, $message);

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
            http_response_code(Status::SERVICE_UNAVAILABLE->value);
        }
        return Command::FAILURE;
    }

    return Command::SUCCESS;
}, $request);

if (Command::SUCCESS !== $exitCode) {
    exit($exitCode);
}
