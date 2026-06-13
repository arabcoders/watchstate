<?php

declare(strict_types=1);

namespace App\API;

use App\Libs\Attributes\Route\Route;
use App\Libs\Config;
use App\Libs\Enums\Http\Status;
use App\Libs\Middlewares\AuthorizationMiddleware;
use App\Libs\Options;
use App\Libs\Uri;
use App\Listeners\ProcessWebhookEvent;
use Psr\Http\Message\ResponseInterface as iResponse;
use Psr\Http\Message\ServerRequestInterface as iRequest;

final class WebHook
{
    public const string URL = '%{api.prefix}/webhook';

    public function __construct()
    {
        set_time_limit(0);
    }

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

        $opts = [
            Options::FAIL_FAST_ON_LOCK => true,
            Options::QUEUE_ONLY => true,
        ];

        $post = $request->getParsedBody();
        $data = [
            'server' => $this->filter($request->getServerParams(), $query),
            'get' => $query,
            'cookie' => $request->getCookieParams(),
            'files' => [],
            'body' => null === $post ? (string) $request->getBody() : '',
        ];

        if (null !== $post) {
            $data['post'] = $post;
        }

        queue_event(ProcessWebhookEvent::NAME, $data, $opts);

        return api_response(Status::OK);
    }

    /**
     * Remove fields from server data.
     *
     * @param array $server Server parameters.
     * @param array $query Sanitized query parameters.
     *
     * @return array Filtered server parameters.
     */
    private function filter(array $server, array $query): array
    {
        unset($server['HTTP_AUTHORIZATION']);

        foreach (array_keys($server) as $key) {
            if (false === is_string($key) || false === str_starts_with($key, 'WS_')) {
                continue;
            }

            unset($server[$key]);
        }

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
