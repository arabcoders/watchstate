<?php

declare(strict_types=1);

namespace App\Backends\Emby\Action;

use App\Backends\Common\CommonTrait;
use App\Backends\Common\Context;
use App\Backends\Common\Response;
use Psr\Http\Message\ServerRequestInterface;

/**
 * Class InspectRequest
 *
 * Check if the request originated from emby backend.
 */
class InspectRequest
{
    use CommonTrait;

    /**
     * @var string Action name
     */
    protected string $action = 'emby.inspectRequest';

    /**
     * Wrap the inspector in try response block.
     *
     * @param Context $context The context object.
     * @param ServerRequestInterface $request The server request object.
     *
     * @return Response The response object.
     */
    public function __invoke(Context $context, ServerRequestInterface $request): Response
    {
        return $this->tryResponse(
            context: $context,
            fn: function () use ($request) {
                if (null === ($payload = ag($request->getParsedBody() ?? [], 'data', null))) {
                    return new Response(status: false, response: $request);
                }

                $json = json_decode(
                    json: (string)$payload,
                    associative: true,
                    flags: JSON_INVALID_UTF8_IGNORE | JSON_THROW_ON_ERROR
                );

                $alteredRequest = $request->withParsedBody($json);

                $attributes = [
                    'backend' => [
                        'id' => ag($json, 'Server.Id', ''),
                        'name' => ag($json, 'Server.Name'),
                        'client' => 'Emby',
                        'version' => ag($json, 'Server.Version', ''),
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
            },
            action: $this->action
        );
    }
}
