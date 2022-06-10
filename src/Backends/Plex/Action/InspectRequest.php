<?php

declare(strict_types=1);

namespace App\Backends\Plex\Action;

use App\Backends\Common\CommonTrait;
use App\Backends\Plex\PlexTrait;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Log\LoggerInterface;
use Throwable;

class InspectRequest
{
    use CommonTrait, PlexTrait;

    public function __construct(protected LoggerInterface $logger)
    {
    }

    public function __invoke(ServerRequestInterface $request): ServerRequestInterface
    {
        try {
            $userAgent = ag($request->getServerParams(), 'HTTP_USER_AGENT', '');

            if (false === str_starts_with($userAgent, 'PlexMediaServer/')) {
                return $request;
            }

            $payload = ag($request->getParsedBody() ?? [], 'payload', null);

            if (null === ($json = json_decode(json: $payload, associative: true, flags: JSON_INVALID_UTF8_IGNORE))) {
                return $request;
            }

            $request = $request->withParsedBody($json);

            $attributes = [
                'PARSED' => true,
                'ITEM_ID' => ag($json, 'Metadata.ratingKey', ''),
                'SERVER_ID' => ag($json, 'Server.uuid', ''),
                'SERVER_NAME' => ag($json, 'Server.title', ''),
                'SERVER_VERSION' => afterLast($userAgent, '/'),
                'USER_ID' => ag($json, 'Account.id', ''),
                'USER_NAME' => ag($json, 'Account.title', ''),
                'WH_EVENT' => ag($json, 'event', 'not_set'),
                'WH_TYPE' => ag($json, 'Metadata.type', 'not_set'),
            ];

            foreach ($attributes as $key => $val) {
                $request = $request->withAttribute($key, $val);
            }

            syslog(LOG_DEBUG, print_r($attributes, true));
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
