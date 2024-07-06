<?php

declare(strict_types=1);

namespace App\Backends\Jellyfin\Action;

use App\Backends\Common\CommonTrait;
use App\Backends\Common\Context;
use App\Backends\Common\Error;
use App\Backends\Common\Levels;
use App\Backends\Common\Response;
use App\Backends\Jellyfin\JellyfinClient as JFC;
use App\Libs\Entity\StateInterface as iState;
use App\Libs\Exceptions\RuntimeException;
use App\Libs\Options;
use DateInterval;
use JsonException;
use Psr\Log\LoggerInterface;
use Symfony\Contracts\HttpClient\Exception\ExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Class GetLibrariesList
 *
 * A class for getting the backend libraries list.
 */
class GetLibrariesList
{
    use CommonTrait;

    protected string $action = 'jellyfin.getLibrariesList';

    /**
     * Class constructor
     *
     * @param HttpClientInterface $http The HTTP client object.
     * @param LoggerInterface $logger The logger object.
     */
    public function __construct(protected HttpClientInterface $http, protected LoggerInterface $logger)
    {
    }

    /**
     * Get backend libraries list.
     *
     * @param Context $context Backend context.
     * @param array $opts (Optional) options.
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
     * @throws ExceptionInterface When an error happens during the request.
     * @throws JsonException When the JSON response cannot be parsed.
     */
    private function action(Context $context, array $opts = []): Response
    {
        $cls = fn() => $this->real_request($context);

        try {
            $json = true === (bool)ag($opts, Options::NO_CACHE) ? $cls() : $this->tryCache(
                $context,
                'library_list',
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

        $listDirs = ag($json, 'Items', []);

        if (empty($listDirs)) {
            return new Response(
                status: false,
                error: new Error(
                    message: 'Request for [{backend}] libraries returned empty list.',
                    context: [
                        'backend' => $context->backendName,
                        'response' => ['body' => $json],
                    ],
                    level: Levels::WARNING
                ),
            );
        }

        if (null !== ($ignoreIds = ag($context->options, Options::IGNORE, null))) {
            $ignoreIds = array_map(fn($v) => trim($v), explode(',', (string)$ignoreIds));
        }

        $list = [];

        foreach ($listDirs as $section) {
            $key = (string)ag($section, 'Id');
            $type = ag($section, 'CollectionType', 'unknown');

            if (JFC::CLIENT_NAME === $context->clientName) {
                $fragment = '/tv.html?topParentId={id}&serverId={backend_id}';
            } else {
                $fragment = '!/tv?serverId={backend_id}&parentId={id}';
            }

            $webUrl = $context->backendUrl->withPath('/web/index.html')->withFragment(r($fragment, [
                'backend_id' => $context->backendId,
                'id' => $key,
            ]));

            $contentType = match ($type) {
                JFC::COLLECTION_TYPE_SHOWS => iState::TYPE_SHOW,
                JFC::COLLECTION_TYPE_MOVIES => iState::TYPE_MOVIE,
                default => 'Unknown',
            };

            $builder = [
                'id' => $key,
                'title' => ag($section, 'Name', '???'),
                'type' => ucfirst($type),
                'ignored' => null !== $ignoreIds && in_array($key, $ignoreIds),
                'supported' => in_array($contentType, [iState::TYPE_SHOW, iState::TYPE_MOVIE]),
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
     * @throws ExceptionInterface When an error happens during the request.
     * @throws JsonException When the JSON response cannot be parsed.
     */
    private function real_request(Context $context): array
    {
        $url = $context->backendUrl->withPath(r('/Users/{user_id}/items/', ['user_id' => $context->backendUser]));

        $this->logger->debug('Requesting [{backend}] libraries list.', [
            'backend' => $context->backendName,
            'url' => (string)$url
        ]);

        $response = $this->http->request('GET', (string)$url, $context->backendHeaders);

        if (200 !== $response->getStatusCode()) {
            throw new RuntimeException(
                r('Request for [{backend}] libraries returned with unexpected [{status_code}] status code.', [
                    'backend' => $context->backendName,
                    'status_code' => $response->getStatusCode(),
                ])
            );
        }

        return json_decode(
            json: $response->getContent(),
            associative: true,
            flags: JSON_THROW_ON_ERROR | JSON_INVALID_UTF8_IGNORE
        );
    }
}
