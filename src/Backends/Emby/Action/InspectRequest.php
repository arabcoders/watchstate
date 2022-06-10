<?php

declare(strict_types=1);

namespace App\Backends\Emby\Action;

use App\Backends\Common\CommonTrait;
use App\Backends\Common\Response;
use App\Backends\Common\Context;
use Psr\Http\Message\ServerRequestInterface;

class InspectRequest
{
    use CommonTrait;

    public function __invoke(Context $context, ServerRequestInterface $request): Response
    {
        return $this->tryResponse(
            context: $context,
            fn: function () use ($request) {
                $userAgent = ag($request->getServerParams(), 'HTTP_USER_AGENT', '');

                if (false === str_starts_with($userAgent, 'Emby Server/')) {
                    return new Response(status: false);
                }

                $payload = (string)ag($request->getParsedBody() ?? [], 'data', null);

                $json = json_decode(
                    json:        $payload,
                    associative: true,
                    flags:       JSON_INVALID_UTF8_IGNORE | JSON_THROW_ON_ERROR
                );

                $alteredRequest = $request->withParsedBody($json);

                $attributes = [
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
                    $alteredRequest = $alteredRequest->withAttribute($key, $val);
                }

                return new Response(status: true, response: $alteredRequest);
            }
        );
    }
}
