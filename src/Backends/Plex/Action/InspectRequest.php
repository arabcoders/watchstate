<?php

declare(strict_types=1);

namespace App\Backends\Plex\Action;

use App\Backends\Common\CommonTrait;
use App\Backends\Common\Response;
use App\Backends\Common\Context;
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

                if (false === str_starts_with($userAgent, 'PlexMediaServer/')) {
                    return new Response(status: false);
                }

                $payload = ag($request->getParsedBody() ?? [], 'payload', null);

                $json = json_decode(
                    json:        $payload,
                    associative: true,
                    flags:       JSON_INVALID_UTF8_IGNORE | JSON_THROW_ON_ERROR
                );

                $alteredRequest = $request->withParsedBody($json);

                $attributes = [
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
                    $alteredRequest = $alteredRequest->withAttribute($key, $val);
                }

                return new Response(status: true, response: $alteredRequest);
            }
        );
    }
}
