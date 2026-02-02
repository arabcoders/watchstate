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
            fn: static function () use ($request, $context) {
                $userAgent = trim(ag($request->getServerParams(), 'HTTP_USER_AGENT', ''));
                $isTautulli = false !== str_starts_with($userAgent, 'Tautulli/');
                $isPlex = false !== str_starts_with($userAgent, 'PlexMediaServer/');

                if (false === $isPlex && false === $isTautulli) {
                    return new Response(status: false);
                }

                $json = null;

                if (true === $isPlex) {
                    $payload = ag($request->getParsedBody() ?? [], 'payload', null);
                    $json = json_decode(
                        json: $payload,
                        associative: true,
                        flags: JSON_INVALID_UTF8_IGNORE | JSON_THROW_ON_ERROR,
                    );
                }

                if (true === $isTautulli && null !== ($json = ag($request->getParsedBody(), null, null))) {
                    $json = self::fix_tautulli_event(item: $json, event: ag($json, 'event', ''));
                }

                if (null === $json) {
                    return new Response(status: false);
                }

                $alteredRequest = $request->withParsedBody($json);

                $attributes = [
                    'backend' => [
                        'id' => ag($json, 'Server.uuid', ''),
                        'name' => ag($json, 'Server.title'),
                        'client' => $context->clientName,
                        'version' => ag($json, 'Server.version', static fn() => after_last($userAgent, '/')),
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
                        'generic' => in_array(ag($json, 'event'), ParseWebhook::WEBHOOK_GENERIC_EVENTS, true),
                    ],
                ];

                foreach ($attributes as $key => $val) {
                    $alteredRequest = $alteredRequest->withAttribute($key, $val);
                }

                return new Response(status: true, response: $alteredRequest);
            },
            action: $this->action,
        );
    }

    /**
     * Fix Tautulli event data
     *
     * @param array $item The item to fix
     * @param string $event The event type
     * @return array The fixed item.
     */
    public static function fix_tautulli_event(array $item, string $event): array
    {
        if (empty($item) || empty($event)) {
            return $item;
        }

        $item = ag_sets($item, [
            'Account.id' => (int) ag($item, 'Account.id', 0),
            'Player.local' => (bool) ag($item, 'Player.local', false),
            'Metadata.index' => (int) ag($item, 'Metadata.index', 0),
            'Metadata.parentIndex' => (int) ag($item, 'Metadata.parentIndex', 0),
            'Metadata.audienceRating' => (float) ag($item, 'Metadata.audienceRating', 0),
            'Metadata.viewOffset' => (int) ag($item, 'Metadata.viewOffset', 0),
            'Metadata.year' => (int) ag($item, 'Metadata.year', 0),
            'Metadata.duration' => (int) ag($item, 'Metadata.duration', 0),
            'Metadata.addedAt' => make_date(ag($item, 'Metadata.addedAt'))->getTimestamp(),
            'Metadata.updatedAt' => make_date(ag($item, 'Metadata.updatedAt'))->getTimestamp(),
            'Metadata.lastViewedAt' => null,
            'Metadata.Guid' => [],
            'Metadata.viewCount' => 0,
        ]);

        $lastViewedAt = ag($item, 'Metadata.lastViewedAt', '');
        if (!empty($lastViewedAt)) {
            $item = ag_set($item, 'Metadata.lastViewedAt', make_date($lastViewedAt)->getTimestamp());
        }

        if (null !== ($guids = ag($item, 'Metadata.Guids', null))) {
            foreach ($guids as $key => $val) {
                if (empty($val)) {
                    continue;
                }
                $item['Metadata']['Guid'][] = ['id' => "{$key}://{$val}"];
            }
        }

        if ('tautulli.watched' === $event) {
            $item = ag_sets($item, ['Metadata.viewCount' => 1, 'Metadata.lastViewedAt' => time()]);
        }

        return $item;
    }
}
