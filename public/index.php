<?php

declare(strict_types=1);

use App\Libs\Config;
use App\Libs\Container;
use App\Libs\HttpException;
use App\Libs\Servers\ServerInterface;
use App\Libs\Storage\StorageInterface;
use Nyholm\Psr7\Response;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Log\LoggerInterface;

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
            return new Response(status: 200, headers: ['X-Status' => 'No GUIDs.']);
        }

        $storage = Container::get(StorageInterface::class);

        if (null === ($backend = $storage->get($entity))) {
            $storage->insert($entity);
            return jsonResponse(status: 200, body: $entity->getAll());
        }

        if ($backend->updated > $entity->updated) {
            return new Response(
                status: 200,
                headers: ['X-Status' => 'Entity date is older than what available in storage.']
            );
        }

        if ($backend->apply($entity)->isChanged()) {
            $backend = $storage->update($backend);

            return jsonResponse(status: 200, body: $backend->getAll());
        }

        return new Response(status: 200, headers: ['X-Status' => 'Entity is unchanged.']);
    } catch (HttpException $e) {
        Container::get(LoggerInterface::class)->error($e->getMessage());

        if (200 === $e->getCode()) {
            return new Response(status: $e->getCode(), headers: ['X-Status' => $e->getMessage()]);
        }

        return jsonResponse(status: $e->getCode(), body: ['error' => true, 'message' => $e->getMessage()]);
    }
};

(new App\Libs\KernelConsole())->boot()->runHttp($fn);
