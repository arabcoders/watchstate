<?php

declare(strict_types=1);

namespace App\API;

use App\Commands\System\TasksCommand;
use App\Libs\Attributes\Route\Route;
use App\Libs\Config;
use App\Libs\Enums\Http\Status;
use App\Libs\Middlewares\AuthorizationMiddleware;
use App\Libs\Options;
use App\Libs\Uri;
use App\Listeners\ProcessWebhookEvent;
use DateInterval;
use Psr\Http\Message\ResponseInterface as iResponse;
use Psr\Http\Message\ServerRequestInterface as iRequest;
use Psr\SimpleCache\CacheInterface as iCache;

final class WebHook
{
    public const string URL = '%{api.prefix}/webhook';

    public function __construct(
        private readonly iCache $cache,
    ) {}

    /**
     * Receive a webhook request from a backend.
     *
     * @param iRequest $request The incoming request object.
     *
     * @return iResponse The response object.
     */
    #[Route(['GET', 'POST', 'PUT'], WebHook::URL . '[/]', name: 'webhook.receive')]
    public function __invoke(iRequest $request): iResponse
    {
        if ('GET' === $request->getMethod()) {
            return api_message('Webhook endpoint is ready.', Status::OK);
        }

        if (true === Config::get('webhook.dumpRequest')) {
            save_request_payload(clone $request);
        }

        $query = array_diff_key(
            $request->getQueryParams(),
            array_flip([AuthorizationMiddleware::KEY_NAME, AuthorizationMiddleware::TOKEN_NAME]),
        );

        $opts = [];

        if (true === (bool) $this->cache->get(TasksCommand::CACHE_NAME, false)) {
            $opts[Options::CACHE_ONLY] = true;
            $opts[Options::CACHE_TTL] = new DateInterval('PT6H');
        }

        queue_event(
            event: ProcessWebhookEvent::NAME,
            data: [
                'server' => $this->filter($request->getServerParams(), $query),
                'get' => $query,
                'post' => $request->getParsedBody(),
                'cookie' => $request->getCookieParams(),
                'files' => [],
                'body' => (string) $request->getBody(),
            ],
            opts: $opts,
        );

        return api_response(Status::OK);
    }

    /**
     * Remove authorization-only fields from queued server data.
     *
     * @param array $server Server parameters.
     * @param array $query Sanitized query parameters.
     *
     * @return array Filtered server parameters.
     */
    private function filter(array $server, array $query): array
    {
        unset($server['HTTP_AUTHORIZATION']);

        if (true === array_key_exists('QUERY_STRING', $server)) {
            $server['QUERY_STRING'] = http_build_query($query);
        }

        if (true === array_key_exists('REQUEST_URI', $server)) {
            $server['REQUEST_URI'] = (string) new Uri((string) $server['REQUEST_URI'])
                ->withQuery(http_build_query($query));
        }

        return $server;
    }
}
