<?php

declare(strict_types=1);

namespace App\Backends\Plex\Action;

use App\Backends\Common\CommonTrait;
use App\Backends\Common\Context;
use App\Backends\Common\Response;
use Psr\Http\Message\ServerRequestInterface;

final class InspectRequest
{
    use CommonTrait;

    private string $action = 'plex.inspectRequest';

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
                    json: $payload,
                    associative: true,
                    flags: JSON_INVALID_UTF8_IGNORE | JSON_THROW_ON_ERROR
                );

                $alteredRequest = $request->withParsedBody($json);

                $attributes = [
                    'backend' => [
                        'id' => ag($json, 'Server.uuid', ''),
                        'name' => ag($json, 'Server.title'),
                        'client' => before($userAgent, '/'),
                        'version' => ag($json, 'Server.version', fn() => afterLast($userAgent, '/')),
                    ],
                    'user' => [
                        'id' => ag($json, 'Account.id', ''),
                        'name' => ag($json, 'Account.title'),
                    ],
                    'item' => [
                        'id' => ag($json, 'Metadata.ratingKey'),
                        'type' => ag($json, 'Metadata.type'),
                    ],
                    'webhook' => [
                        'event' => ag($json, 'event'),
                    ],
                ];

                foreach ($attributes as $key => $val) {
                    $alteredRequest = $alteredRequest->withAttribute($key, $val);
                }

                return new Response(status: true, response: $alteredRequest);
            },
            action: $this->action
        );
    }
}
