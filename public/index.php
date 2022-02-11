<?php

declare(strict_types=1);

use App\Libs\Config;
use App\Libs\Container;
use App\Libs\HttpException;
use App\Libs\Servers\ServerInterface;
use App\Libs\Storage\StorageInterface;
use Laminas\Diactoros\Response\EmptyResponse;
use Laminas\Diactoros\Response\JsonResponse;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Log\LoggerInterface;

error_reporting(E_ALL);
ini_set('error_reporting', 'On');
ini_set('display_errors', 'Off');

if (!defined('BASE_MEMORY')) {
    define('BASE_MEMORY', memory_get_usage());
}

if (!defined('BASE_PEAK_MEMORY')) {
    define('BASE_PEAK_MEMORY', memory_get_peak_usage());
}

if (!defined('DS')) {
    define('DS', DIRECTORY_SEPARATOR);
}

if (!defined('ROOT_PATH')) {
    define('ROOT_PATH', realpath(__DIR__ . '..' . DS));
}

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

$fn = function (ServerRequestInterface $request): ResponseInterface {
    try {
        if (true !== Config::get('webhook.enabled', false)) {
            throw new HttpException('Webhook is disabled via config.', 500);
        }

        if (null === Config::get('webhook.apikey', null)) {
            throw new HttpException('No webhook.apikey is set in config.', 500);
        }

        // -- get apikey from header or query.
        $apikey = $request->getHeaderLine('x-apikey');
        if (empty($apikey)) {
            $apikey = ag($request->getQueryParams(), 'apikey', '');
            if (empty($apikey)) {
                throw new HttpException('No API key was given.', 400);
            }
        }

        if (!hash_equals(Config::get('webhook.apikey'), $apikey)) {
            throw new HttpException('Invalid API key was given.', 401);
        }

        if (null === ($type = ag($request->getQueryParams(), 'type', null))) {
            throw new HttpException('No type was given via type= query.', 400);
        }

        $types = Config::get('supported', []);

        if (null === ($backend = ag($types, $type))) {
            throw new HttpException('Invalid server type was given.', 400);
        }

        $class = new ReflectionClass($backend);

        if (!$class->implementsInterface(ServerInterface::class)) {
            throw new HttpException('Invalid Parser Class.', 500);
        }

        /** @var ServerInterface $backend */
        $entity = $backend::parseWebhook($request);

        if (null === $entity || !$entity->hasGuids()) {
            return new EmptyResponse(200, ['X-Status' => 'No GUIDs.']);
        }

        $storage = Container::get(StorageInterface::class);

        if (null === ($backend = $storage->get($entity))) {
            $storage->insert($entity);
            return new JsonResponse($entity->getAll(), 200);
        }

        if ($backend->updated > $entity->updated) {
            return new EmptyResponse(200, ['X-Status' => 'Entity date is older than what available in storage.']);
        }

        if ($backend->apply($entity)->isChanged()) {
            $backend = $storage->update($backend);

            return new JsonResponse($backend->getAll(), 200);
        }

        return new EmptyResponse(200, ['X-Status' => 'Entity is unchanged.']);
    } catch (HttpException $e) {
        Container::get(LoggerInterface::class)->error($e->getMessage());

        if (200 === $e->getCode()) {
            return new EmptyResponse($e->getCode(), [
                'X-Status' => $e->getMessage(),
            ]);
        }

        return new JsonResponse(
            [
                'error' => true,
                'message' => $e->getMessage(),
            ],
            $e->getCode()
        );
    }
};

(new App\Libs\KernelConsole())->boot()->runHttp($fn);
