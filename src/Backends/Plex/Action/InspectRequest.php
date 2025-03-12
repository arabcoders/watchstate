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
                        flags: JSON_INVALID_UTF8_IGNORE | JSON_THROW_ON_ERROR
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

        $item = ag_set($item, 'Account.id', (int)ag($item, 'Account.id', 0));
        $item = ag_set($item, 'Player.local', (bool)ag($item, 'Player.local', false));
        $item = ag_set($item, 'Metadata.index', (int)ag($item, 'Metadata.index', 0));
        $item = ag_set($item, 'Metadata.parentIndex', (int)ag($item, 'Metadata.parentIndex', 0));
        $item = ag_set($item, 'Metadata.audienceRating', (float)ag($item, 'Metadata.audienceRating', 0));
        $item = ag_set($item, 'Metadata.viewOffset', (int)ag($item, 'Metadata.viewOffset', 0));
        if ('' === ag($item, 'Metadata.lastViewedAt', '')) {
            $item = ag_set($item, 'Metadata.lastViewedAt', null);
            $item = ag_set($item, 'Metadata.viewCount', 0);
        } else {
            $item = ag_set(
                $item,
                'Metadata.lastViewedAt',
                makeDate(ag($item, 'Metadata.lastViewedAt'))->getTimestamp()
            );
            $item = ag_set($item, 'Metadata.viewCount', 1);
        }
        $item = ag_set($item, 'Metadata.year', (int)ag($item, 'Metadata.year', 0));
        $item = ag_set($item, 'Metadata.duration', (int)ag($item, 'Metadata.duration', 0));
        $item = ag_set($item, 'Metadata.addedAt', makeDate(ag($item, 'Metadata.addedAt'))->getTimestamp());
        $item = ag_set($item, 'Metadata.updatedAt', makeDate(ag($item, 'Metadata.updatedAt'))->getTimestamp());
        $item = ag_set($item, 'Metadata.Guid', []);

        if (null !== ($guids = ag($item, 'Metadata.Guids', null))) {
            foreach ($guids as $key => $val) {
                if (empty($val)) {
                    continue;
                }
                $item['Metadata']['Guid'][] = ['id' => "{$key}://{$val}"];
            }
        }

        if ('tautulli.watched' === $event) {
            $item = ag_set($item, 'Metadata.viewCount', 1);
            $item = ag_set($item, 'Metadata.lastViewedAt', time());
        }

        return $item;
    }
}
