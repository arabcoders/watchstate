<?php

declare(strict_types=1);

namespace App\Backends\Emby\Action;

use App\Backends\Common\CommonTrait;
use App\Backends\Emby\EmbyTrait;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Log\LoggerInterface;
use Throwable;

class InspectRequest
{
    use CommonTrait, EmbyTrait;

    public function __construct(protected LoggerInterface $logger)
    {
    }

    public function processRequest(ServerRequestInterface $request): ServerRequestInterface
    {
        try {
            $userAgent = ag($request->getServerParams(), 'HTTP_USER_AGENT', '');

            if (false === str_starts_with($userAgent, 'Emby Server/')) {
                return $request;
            }

            $payload = (string)ag($request->getParsedBody() ?? [], 'data', null);

            if (null === ($json = json_decode(json: $payload, associative: true, flags: JSON_INVALID_UTF8_IGNORE))) {
                return $request;
            }

            $request = $request->withParsedBody($json);

            $attributes = [
                'PARSED' => true,
                'ITEM_ID' => ag($json, 'Item.Id', ''),
                'SERVER_ID' => ag($json, 'Server.Id', ''),
                'SERVER_NAME' => ag($json, 'Server.Name', ''),
                'SERVER_VERSION' => afterLast($userAgent, '/'),
                'USER_ID' => ag($json, 'User.Id', ''),
                'USER_NAME' => ag($json, 'User.Name', ''),
                'WH_EVENT' => ag($json, 'Event', 'not_set'),
                'WH_TYPE' => ag($json, 'Item.Type', 'not_set'),
            ];

            foreach ($attributes as $key => $val) {
                $request = $request->withAttribute($key, $val);
            }
        } catch (Throwable $e) {
            $this->logger->error('Unhandled exception was thrown during [%(client)] request inspection.', [
                'client' => $this->getClientName(),
                'exception' => [
                    'file' => $e->getFile(),
                    'line' => $e->getLine(),
                    'kind' => get_class($e),
                    'message' => $e->getMessage(),
                ],
            ]);
        }

        return $request;
    }

}
