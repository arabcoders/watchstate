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
                    'ITEM_ID' => ag($json, 'ItemId', ''),
                    'SERVER_ID' => ag($json, 'ServerId', ''),
                    'SERVER_NAME' => ag($json, 'ServerName', ''),
                    'SERVER_CLIENT' => before($userAgent, '/'),
                    'SERVER_VERSION' => ag($json, 'ServerVersion', fn() => afterLast($userAgent, '/')),
                    'USER_ID' => ag($json, 'UserId', ''),
                    'USER_NAME' => ag($json, 'NotificationUsername', ''),
                    'WH_EVENT' => ag($json, 'NotificationType', 'not_set'),
                    'WH_TYPE' => ag($json, 'ItemType', 'not_set'),
                ];

                foreach ($attributes as $key => $val) {
                    $alteredRequest = $alteredRequest->withAttribute($key, $val);
                }

                return new Response(status: true, response: $alteredRequest);
            }
        );
    }
}
