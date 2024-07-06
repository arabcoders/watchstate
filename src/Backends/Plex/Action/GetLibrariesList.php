<?php

declare(strict_types=1);

namespace App\Backends\Plex\Action;

use App\Backends\Common\CommonTrait;
use App\Backends\Common\Context;
use App\Backends\Common\Error;
use App\Backends\Common\Levels;
use App\Backends\Common\Response;
use App\Backends\Plex\PlexClient;
use App\Libs\Entity\StateInterface as iState;
use App\Libs\Exceptions\RuntimeException;
use App\Libs\Options;
use DateInterval;
use JsonException;
use Psr\Log\LoggerInterface;
use Symfony\Contracts\HttpClient\Exception\ExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

final class GetLibrariesList
{
    use CommonTrait;

    private string $action = 'plex.getLibrariesList';

    public function __construct(protected HttpClientInterface $http, protected LoggerInterface $logger)
    {
    }

    /**
     * Get Backend libraries list.
     *
     * @param Context $context
     * @param array $opts optional options.
     *
     * @return Response
     */
    public function __invoke(Context $context, array $opts = []): Response
    {
        return $this->tryResponse(context: $context, fn: fn() => $this->action($context, $opts), action: $this->action);
    }

    /**
     * Fetches libraries from the backend.
     *
     * @param Context $context Backend context.
     * @param array $opts (optional) Options.
     *
     * @return Response The response object containing the fetched libraries.
     *
     * @throws ExceptionInterface when an error happens during the request.
     * @throws JsonException when the response is not a valid JSON.
     */
    private function action(Context $context, array $opts = []): Response
    {
        try {
            $cls = fn() => $this->real_request($context);

            $json = true === (bool)ag($opts, Options::NO_CACHE) ? $cls() : $this->tryCache(
                $context,
                'libraries_list',
                $cls,
                new DateInterval('PT1M'),
                $this->logger
            );
        } catch (RuntimeException $e) {
            return new Response(status: false, error: new Error(message: $e->getMessage(), level: Levels::ERROR));
        }

        if ($context->trace) {
            $this->logger->debug('Parsing [{backend}] libraries payload.', [
                'backend' => $context->backendName,
                'trace' => $json,
            ]);
        }

        $listDirs = ag($json, 'MediaContainer.Directory', []);

        if (empty($listDirs)) {
            return new Response(
                status: false,
                error: new Error(
                    message: 'Request for [{backend}] libraries returned empty list.',
                    context: [
                        'backend' => $context->backendName,
                        'response' => [
                            'body' => $json
                        ],
                    ],
                    level: Levels::WARNING
                ),
            );
        }

        if (null !== ($ignoreIds = ag($context->options, 'ignore', null))) {
            $ignoreIds = array_map(fn($v) => (int)trim($v), explode(',', (string)$ignoreIds));
        }

        $list = [];

        foreach ($listDirs as $section) {
            $key = (int)ag($section, 'key');
            $type = ag($section, 'type', 'unknown');
            $agent = ag($section, 'agent', 'unknown');
            $supportedType = PlexClient::TYPE_MOVIE === $type || PlexClient::TYPE_SHOW === $type;

            $webUrl = $context->backendUrl->withPath('/web/index.html')->withFragment(
                r('!/media/{backend_id}/com.plexapp.plugins.library?source={key}', [
                    'key' => $key,
                    'backend_id' => $context->backendId,
                ])
            );

            $contentType = match ($type) {
                PlexClient::TYPE_SHOW => iState::TYPE_SHOW,
                PlexClient::TYPE_MOVIE => iState::TYPE_MOVIE,
                default => 'Unknown',
            };

            $builder = [
                'id' => $key,
                'title' => ag($section, 'title', '???'),
                'type' => ucfirst($type),
                'ignored' => true === in_array($key, $ignoreIds ?? []),
                'supported' => $supportedType && true === in_array($agent, PlexClient::SUPPORTED_AGENTS),
                'agent' => ag($section, 'agent'),
                'scanner' => ag($section, 'scanner'),
                'contentType' => $contentType,
                'webUrl' => (string)$webUrl,
            ];

            if (true === (bool)ag($opts, Options::RAW_RESPONSE)) {
                $builder['raw'] = $section;
            }

            $list[] = $builder;
        }

        return new Response(status: true, response: $list);
    }

    /**
     * Fetches the libraries from the backend.
     *
     * @return array The fetched libraries.
     *
     * @throws ExceptionInterface when an error happens during the request.
     * @throws JsonException when the response is not a valid JSON.
     */
    private function real_request(Context $context): array
    {
        $url = $context->backendUrl->withPath('/library/sections');

        $this->logger->debug('Requesting [{backend}] libraries list.', [
            'backend' => $context->backendName,
            'url' => (string)$url
        ]);

        $response = $this->http->request('GET', (string)$url, $context->backendHeaders);

        $payload = $response->getContent(false);

        if ($context->trace) {
            $this->logger->debug('Processing [{backend}] response.', [
                'backend' => $context->backendName,
                'url' => (string)$url,
                'response' => $payload,
            ]);
        }

        if (200 !== $response->getStatusCode()) {
            throw new RuntimeException(
                r(
                    'Request for [{backend}] libraries returned with unexpected [{status_code}] status code.',
                    [
                        'backend' => $context->backendName,
                        'status_code' => $response->getStatusCode(),
                    ]
                )
            );
        }

        return json_decode(
            json: $payload,
            associative: true,
            flags: JSON_THROW_ON_ERROR | JSON_INVALID_UTF8_IGNORE
        );
    }
}
