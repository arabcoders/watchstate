<?php

declare(strict_types=1);

namespace App\Backends\Emby\Action;

use App\Backends\Common\CommonTrait;
use App\Backends\Common\Context;
use App\Backends\Common\Response;
use JsonException;
use Psr\Http\Message\ServerRequestInterface;
use RuntimeException;

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
                $parsed = $request->getParsedBody();

                // -- backwards compatibility for emby 4.8.x
                if (is_array($parsed) && false !== ag_exists($parsed, 'data')) {
                    $payload = ag($request->getParsedBody(), 'data', null);
                    if (empty($payload)) {
                        return new Response(status: false, response: $request);
                    }
                } else {
                    $payload = (string)$request->getBody();
                }

                if (empty($payload)) {
                    return new Response(status: false, response: $request);
                }

                try {
                    $json = json_decode(
                        json: $payload,
                        associative: true,
                        flags: JSON_INVALID_UTF8_IGNORE | JSON_THROW_ON_ERROR
                    );


                    if (empty($json)) {
                        throw new RuntimeException('invalid json content');
                    }

                    $request = $request->withParsedBody($json);
                } catch (JsonException|RuntimeException) {
                    return new Response(status: false, response: $request);
                }

                if (null === ($json = $request->getParsedBody())) {
                    return new Response(status: false, response: $request);
                }

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
                        'generic' => in_array(ag($json, 'Event'), ParseWebhook::WEBHOOK_GENERIC_EVENTS, true),
                    ],
                ];

                foreach ($attributes as $key => $val) {
                    $request = $request->withAttribute($key, $val);
                }

                return new Response(status: true, response: $request);
            },
            action: $this->action
        );
    }
}
