<?php

declare(strict_types=1);

namespace App\Libs\Servers;

use App\Backends\Emby\Action\InspectRequest;
use App\Backends\Emby\Action\ParseWebhook;
use App\Libs\Container;
use App\Libs\Entity\StateInterface as iFace;
use App\Libs\HttpException;
use Psr\Http\Message\ServerRequestInterface;

class EmbyServer extends JellyfinServer
{
    public const NAME = 'EmbyBackend';

    public function parseWebhook(ServerRequestInterface $request): iFace
    {
        $response = Container::get(ParseWebhook::class)(context: $this->context, guid: $this->guid, request: $request);

        if ($response->hasError()) {
            $this->logger->log($response->error->level(), $response->error->message, $response->error->context);
        }

        if (false === $response->isSuccessful()) {
            throw new HttpException(
                ag($response->extra, 'message', fn() => $response->error->format()),
                ag($response->extra, 'http_code', 400),
            );
        }

        return $response->response;
    }

    public function processRequest(ServerRequestInterface $request, array $opts = []): ServerRequestInterface
    {
        $response = Container::get(InspectRequest::class)(context: $this->context, request: $request);

        return $response->isSuccessful() ? $response->response : $request;
    }
}
