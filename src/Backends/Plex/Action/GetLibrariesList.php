<?php

declare(strict_types=1);

namespace App\Backends\Plex\Action;

use App\Backends\Common\CommonTrait;
use App\Backends\Common\Context;
use App\Backends\Common\Error;
use App\Backends\Common\Levels;
use App\Backends\Common\Response;
use App\Backends\Plex\PlexClient;
use App\Libs\Options;
use JsonException;
use Psr\Log\LoggerInterface;
use Symfony\Contracts\HttpClient\Exception\ExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

final class GetLibrariesList
{
    use CommonTrait;

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
        return $this->tryResponse(context: $context, fn: fn() => $this->action($context, $opts));
    }

    /**
     * @throws ExceptionInterface
     * @throws JsonException
     */
    private function action(Context $context, array $opts = []): Response
    {
        $url = $context->backendUrl->withPath('/library/sections');

        $this->logger->debug('Requesting [%(backend)] libraries list.', [
            'backend' => $context->backendName,
            'url' => (string)$url
        ]);

        $response = $this->http->request('GET', (string)$url, $context->backendHeaders);

        $payload = $response->getContent(false);

        if ($context->trace) {
            $this->logger->debug('Processing [%(backend)] response.', [
                'backend' => $context->backendName,
                'url' => (string)$url,
                'response' => $payload,
            ]);
        }

        if (200 !== $response->getStatusCode()) {
            return new Response(
                status: false,
                error: new Error(
                    message: 'Request for [%(backend)] libraries returned with unexpected [%(status_code)] status code.',
                    context: [
                        'backend' => $context->backendName,
                        'status_code' => $response->getStatusCode(),
                    ],
                    level: Levels::ERROR
                ),
            );
        }

        $json = json_decode(
            json: $payload,
            associative: true,
            flags: JSON_THROW_ON_ERROR | JSON_INVALID_UTF8_IGNORE
        );

        unset($payload);

        if ($context->trace) {
            $this->logger->debug('Parsing [%(backend)] libraries payload.', [
                'backend' => $context->backendName,
                'trace' => $json,
            ]);
        }

        $listDirs = ag($json, 'MediaContainer.Directory', []);

        if (empty($listDirs)) {
            return new Response(
                status: false,
                error: new Error(
                    message: 'Request for [%(backend)] libraries returned empty list.',
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
            $builder = [
                'id' => $key,
                'title' => ag($section, 'title', '???'),
                'type' => ucfirst($type),
                'ignored' => true === in_array($key, $ignoreIds ?? []),
                'supported' => true === in_array($agent, PlexClient::SUPPORTED_AGENTS),
                'agent' => ag($section, 'agent'),
                'scanner' => ag($section, 'scanner'),
            ];

            if (true === (bool)ag($opts, Options::RAW_RESPONSE)) {
                $builder['raw'] = $section;
            }

            $list[] = $builder;
        }

        return new Response(status: true, response: $list);
    }
}
