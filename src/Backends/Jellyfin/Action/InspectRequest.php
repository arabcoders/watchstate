<?php

declare(strict_types=1);

namespace App\Backends\Jellyfin\Action;

use App\Backends\Common\CommonTrait;
use App\Backends\Common\Context;
use App\Backends\Common\Response;
use Psr\Http\Message\ServerRequestInterface as iRequest;

/**
 * Class InspectRequest
 *
 * Check if the request originated from emby backend.
 */
final class InspectRequest
{
    use CommonTrait;

    /**
     * @var string Action name
     */
    protected string $action = 'jellyfin.inspectRequest';

    /**
     * Wrap the inspector in try response block.
     *
     * @param Context $context Backend context.
     * @param iRequest $request Request object.
     *
     * @return Response The response.
     */
    public function __invoke(Context $context, iRequest $request): Response
    {
        return $this->tryResponse(
            context: $context,
            fn: static function () use ($request, $context) {
                $userAgent = (string) ag($request->getServerParams(), 'HTTP_USER_AGENT', '');

                if (false === str_starts_with($userAgent, 'Jellyfin-Server/')) {
                    return new Response(status: false);
                }

                // we cannot assume `getParsedBody()` is populated with JSON data, as by default jellyfin
                // sets the content-type to `text/plain` for generic destnation webhook so we need to check it and
                // parse the body if needed.
                if (null === ($json = $request->getParsedBody()) || false === is_array($json)) {
                    $body = (string) $request->getBody();

                    if ($request->getBody()->isSeekable()) {
                        $request->getBody()->rewind();
                    }

                    if (empty($body) || false === json_validate($body)) {
                        return new Response(status: false, response: $request);
                    }

                    $json = json_decode(
                        json: $body,
                        associative: true,
                        flags: JSON_INVALID_UTF8_IGNORE | JSON_THROW_ON_ERROR,
                    );

                    $request = $request->withParsedBody($json);
                }

                $attributes = [
                    'backend' => [
                        'id' => ag($json, 'ServerId', ''),
                        'name' => ag($json, 'ServerName'),
                        'client' => $context->clientName,
                        'version' => ag($json, 'ServerVersion', static fn() => after_last($userAgent, '/')),
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
                        'generic' => in_array(
                            ag($json, 'NotificationType'),
                            ParseWebhook::WEBHOOK_GENERIC_EVENTS,
                            true,
                        ),
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
