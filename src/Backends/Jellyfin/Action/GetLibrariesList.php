<?php

declare(strict_types=1);

namespace App\Backends\Jellyfin\Action;

use App\Backends\Common\CommonTrait;
use App\Backends\Common\Context;
use App\Backends\Common\Error;
use App\Backends\Common\Levels;
use App\Backends\Common\Response;
use App\Backends\Jellyfin\JellyfinClient;
use App\Libs\Options;
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
     * @throws JsonException If the response body is not a valid JSON.
     * @throws ExceptionInterface If the request failed.
     */
    private function action(Context $context, array $opts = []): Response
    {
        $url = $context->backendUrl->withPath(r('/Users/{user_id}/items/', ['user_id' => $context->backendUser]));

        $this->logger->debug('Requesting [{backend}] libraries list.', [
            'backend' => $context->backendName,
            'url' => (string)$url
        ]);

        $response = $this->http->request('GET', (string)$url, $context->backendHeaders);

        if (200 !== $response->getStatusCode()) {
            return new Response(
                status: false,
                error: new Error(
                    message: 'Request for [{backend}] libraries returned with unexpected [{status_code}] status code.',
                    context: [
                        'backend' => $context->backendName,
                        'status_code' => $response->getStatusCode(),
                    ],
                    level: Levels::ERROR
                ),
            );
        }

        $json = json_decode(
            json: $response->getContent(),
            associative: true,
            flags: JSON_THROW_ON_ERROR | JSON_INVALID_UTF8_IGNORE
        );

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

        if (null !== ($ignoreIds = ag($context->options, 'ignore', null))) {
            $ignoreIds = array_map(fn($v) => trim($v), explode(',', (string)$ignoreIds));
        }

        $list = [];

        foreach ($listDirs as $section) {
            $key = (string)ag($section, 'Id');
            $type = ag($section, 'CollectionType', 'unknown');

            $builder = [
                'id' => $key,
                'title' => ag($section, 'Name', '???'),
                'type' => ucfirst($type),
                'ignored' => null !== $ignoreIds && in_array($key, $ignoreIds),
                'supported' => in_array(
                    $type,
                    [JellyfinClient::COLLECTION_TYPE_MOVIES, JellyfinClient::COLLECTION_TYPE_SHOWS]
                ),
            ];

            if (true === (bool)ag($opts, Options::RAW_RESPONSE)) {
                $builder['raw'] = $section;
            }

            $list[] = $builder;
        }

        return new Response(status: true, response: $list);
    }
}
