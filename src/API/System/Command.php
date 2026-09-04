<?php

declare(strict_types=1);

namespace App\API\System;

use App\Commands\System\WorkerCommand;
use App\Libs\Attributes\Route\Delete;
use App\Libs\Attributes\Route\Get;
use App\Libs\Attributes\Route\Post;
use App\Libs\Console\ConsoleSessionService;
use App\Libs\DataUtil;
use App\Libs\Enums\Http\Status;
use App\Libs\Middlewares\SignatureMiddleware;
use DateInterval;
use Psr\Http\Message\ResponseInterface as iResponse;
use Psr\Http\Message\ServerRequestInterface as iRequest;
use Throwable;

final class Command
{
    public const string URL = '%{api.prefix}/system/command';

    public function __construct(
        private readonly ConsoleSessionService $sessions,
    ) {}

    #[Post(self::URL . '[/]', middleware: [SignatureMiddleware::class], name: 'system.command.queue')]
    public function queue(iRequest $request): iResponse
    {
        $params = DataUtil::fromRequest($request);
        if (empty($params->getAll())) {
            return api_error('No json data was given.', Status::BAD_REQUEST);
        }

        $command = $params->get('command');
        if (null === $command) {
            return api_error('No command was given.', Status::BAD_REQUEST);
        }
        if (!is_string($command)) {
            return api_error('Command is invalid.', Status::BAD_REQUEST);
        }

        if (true === str_contains($command, WorkerCommand::ROUTE)) {
            return api_error('The worker command cannot be run from the web console.', Status::FORBIDDEN);
        }

        if (false === $this->sessions->isWorkerRunning()) {
            return api_error('Console command worker is unavailable.', Status::SERVICE_UNAVAILABLE);
        }

        $expires = make_date()->add(new DateInterval('PT5M'));
        try {
            $token = $this->sessions->queue($params->getAll(), $expires->format(DATE_ATOM));
        } catch (Throwable $e) {
            return api_error($e->getMessage(), Status::INTERNAL_SERVER_ERROR);
        }

        return api_response(Status::CREATED, [
            'token' => $token,
            'tracking' => r('{url}/{token}', ['url' => parse_config_value(self::URL), 'token' => $token]),
            'expires' => $expires->format(DATE_ATOM),
        ]);
    }

    #[Get(self::URL . '/{token}[/]', name: 'system.command.stream')]
    public function stream(iRequest $request, #[\SensitiveParameter] string $token): iResponse
    {
        $body = $this->sessions->stream($request, $token);
        if (null === $body) {
            return api_error('Token is invalid or has expired.', Status::NOT_FOUND);
        }

        return api_response(Status::OK, body: $body, headers: [
            'Content-Type' => 'text/event-stream; charset=UTF-8',
            'Cache-Control' => 'no-cache',
            'Connection' => 'keep-alive',
            'X-Accel-Buffering' => 'no',
            'Content-Encoding' => 'none',
        ]);
    }

    #[Delete(self::URL . '/{token}[/]', name: 'system.command.cancel')]
    public function cancel(#[\SensitiveParameter] string $token): iResponse
    {
        $result = $this->sessions->cancel($token);
        if (null === $result) {
            return api_error('Token is invalid or has expired.', Status::NOT_FOUND);
        }

        return api_response(Status::ACCEPTED, ['message' => $result]);
    }

    #[Get(self::URL . '[/]', name: 'system.command.list')]
    public function list(): iResponse
    {
        return api_response(Status::OK, ['items' => $this->sessions->list()]);
    }
}
