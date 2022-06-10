<?php

declare(strict_types=1);

namespace App\Backends\Jellyfin\Action;

use App\Backends\Common\CommonTrait;
use App\Backends\Jellyfin\JellyfinTrait;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Log\LoggerInterface;
use Throwable;

class InspectRequest
{
    use CommonTrait, JellyfinTrait;

    public function __construct(protected LoggerInterface $logger)
    {
    }

    public function __invoke(ServerRequestInterface $request): ServerRequestInterface
    {
        try {
            $userAgent = ag($request->getServerParams(), 'HTTP_USER_AGENT', '');

            if (false === str_starts_with($userAgent, 'Jellyfin-Server/')) {
                return $request;
            }

            $payload = (string)$request->getBody();

            if (null === ($json = json_decode(json: $payload, associative: true, flags: JSON_INVALID_UTF8_IGNORE))) {
                return $request;
            }

            $request = $request->withParsedBody($json);

            $attributes = [
                'PARSED' => true,
                'ITEM_ID' => ag($json, 'ItemId', ''),
                'SERVER_ID' => ag($json, 'ServerId', ''),
                'SERVER_NAME' => ag($json, 'ServerName', ''),
                'SERVER_VERSION' => ag($json, 'ServerVersion', fn() => afterLast($userAgent, '/')),
                'USER_ID' => ag($json, 'UserId', ''),
                'USER_NAME' => ag($json, 'NotificationUsername', ''),
                'WH_EVENT' => ag($json, 'NotificationType', 'not_set'),
                'WH_TYPE' => ag($json, 'ItemType', 'not_set'),
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
