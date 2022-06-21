<?php

declare(strict_types=1);

namespace App\Backends\Emby\Action;

use App\Backends\Common\CommonTrait;
use App\Backends\Common\Context;
use App\Backends\Common\Response;
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
                    json: $payload,
                    associative: true,
                    flags: JSON_INVALID_UTF8_IGNORE | JSON_THROW_ON_ERROR
                );

                $alteredRequest = $request->withParsedBody($json);

                $attributes = [
                    'backend' => [
                        'id' => ag($json, 'Server.Id', ''),
                        'name' => ag($json, 'Server.Name'),
                        'client' => before($userAgent, '/'),
                        'version' => ag($json, 'Server.Version', fn() => afterLast($userAgent, '/')),
                    ],
                    'user' => [
                        'id' => ag($json, 'User.Id', ''),
                        'name' => ag($json, 'User.Name'),
                    ],
                    'item' => [
                        'id' => ag($json, 'Item.Id', ''),
                        'type' => ag($json, 'Item.Type'),
                    ],
                    'webhook' => [
                        'event' => ag($json, 'Event'),
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
