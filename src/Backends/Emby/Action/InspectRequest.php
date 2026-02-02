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
            fn: static function () use ($request, $context) {
                if (null === ($json = $request->getParsedBody()) || false === is_array($json)) {
                    return new Response(status: false, response: $request);
                }

                // -- backwards compatibility for emby 4.8.x
                if (is_array($json) && false !== ag_exists($json, 'data')) {
                    $payload = ag($request->getParsedBody(), 'data', null);
                    if (empty($payload)) {
                        return new Response(status: false, response: $request);
                    }
                    $request = $request->withParsedBody($payload);
                }

                // -- Due to the fact that Emby doesn't give us an actual user agent, we have to rely on the version
                // -- number to determine if the request is from Emby.
                $version = (string) ag($json, 'Server.Version', '0.0.0.0');
                if (version_compare($version, '4.8.0.0', '<') || version_compare($version, '4.99.0.0', '>')) {
                    return new Response(status: false, response: $request);
                }

                $attributes = [
                    'backend' => [
                        'id' => ag($json, 'Server.Id', ''),
                        'name' => ag($json, 'Server.Name'),
                        'client' => $context->clientName,
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
            action: $this->action,
        );
    }
}
