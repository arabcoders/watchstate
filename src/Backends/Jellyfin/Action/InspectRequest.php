<?php

declare(strict_types=1);

namespace App\Backends\Jellyfin\Action;

use App\Backends\Common\CommonTrait;
use App\Backends\Common\Context;
use App\Backends\Common\Response;
use Psr\Http\Message\ServerRequestInterface;

final class InspectRequest
{
    use CommonTrait;

    public function __invoke(Context $context, ServerRequestInterface $request): Response
    {
        return $this->tryResponse(
            context: $context,
            fn: function () use ($request) {
                $userAgent = ag($request->getServerParams(), 'HTTP_USER_AGENT', '');

                if (false === str_starts_with($userAgent, 'Jellyfin-Server/')) {
                    return new Response(status: false);
                }

                $payload = (string)$request->getBody();

                $json = json_decode(
                    json: $payload,
                    associative: true,
                    flags: JSON_INVALID_UTF8_IGNORE | JSON_THROW_ON_ERROR
                );

                $alteredRequest = $request->withParsedBody($json);

                $attributes = [
                    'backend' => [
                        'id' => ag($json, 'ServerId', ''),
                        'name' => ag($json, 'ServerName'),
                        'client' => before($userAgent, '/'),
                        'version' => ag($json, 'ServerVersion', fn() => afterLast($userAgent, '/')),
                    ],
                    'user' => [
                        'id' => ag($json, 'UserId', ''),
                        'name' => ag($json, 'NotificationUsername'),
                    ],
                    'item' => [
                        'id' => ag($json, 'ItemId'),
                        'type' => ag($json, 'ItemType'),
                    ],
                    'webhook' => [
                        'event' => ag($json, 'NotificationType'),
                    ],
                ];

                foreach ($attributes as $key => $val) {
                    $alteredRequest = $alteredRequest->withAttribute($key, $val);
                }

                return new Response(status: true, response: $alteredRequest);
            }
        );
    }
}
