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
use App\Libs\Enums\Http\Method;
use App\Libs\Enums\Http\Status;
use App\Libs\Exceptions\RuntimeException;
use App\Libs\Options;
use DateInterval;
use JsonException;
use Psr\Log\LoggerInterface as iLogger;
use Symfony\Contracts\HttpClient\Exception\ExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface as iHttp;

final class GetLibrariesList
{
    use CommonTrait;

    private string $action = 'plex.getLibrariesList';

    public function __construct(protected iHttp $http, protected iLogger $logger)
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
        $logContext = [
            'action' => $this->action,
            'client' => $context->clientName,
            'backend' => $context->backendName,
            'user' => $context->userContext->name,
        ];

        try {
            $cls = fn() => $this->real_request($context, $logContext);

            $json = true === (bool)ag($opts, Options::NO_CACHE) ? $cls() : $this->tryCache(
                $context,
                'libraries_list',
                $cls,
                new DateInterval('PT1M'),
                $this->logger
            );
        } catch (RuntimeException $e) {
            return new Response(
                status: false,
                error: new Error(message: $e->getMessage(), level: Levels::ERROR, previous: $e)
            );
        }

        if ($context->trace) {
            $this->logger->debug(
                message: "{action}: Parsing '{client}: {user}@{backend}' libraries payload.",
                context: [...$logContext, 'response' => ['body' => $json]],
            );
        }

        $listDirs = ag($json, 'MediaContainer.Directory', []);

        if (empty($listDirs)) {
            return new Response(
                status: false,
                error: new Error(
                    message: "{action}: Request for '{client}: {user}@{backend}' libraries returned empty list.",
                    context: [...$logContext, 'response' => ['key' => 'MediaContainer.Directory', 'body' => $json]],
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
     * @param Context $context Backend context.
     * @param array $logContext Log context.
     *
     * @return array The fetched libraries.
     *
     * @throws ExceptionInterface when an error happens during the request.
     * @throws JsonException when the response is not a valid JSON.
     */
    private function real_request(Context $context, array $logContext = []): array
    {
        $url = $context->backendUrl->withPath('/library/sections');

        $logContext['url'] = (string)$url;

        $this->logger->debug("{action}: Requesting '{client}: {user}@{backend}' libraries list.", $logContext);

        $response = $this->http->request(Method::GET, (string)$url, $context->backendHeaders);

        $payload = $response->getContent(false);

        if ($context->trace) {
            $this->logger->debug(
                message: "{action}: Processing '{client}: {user}@{backend}' response.",
                context: [...$logContext, 'response' => ['body' => $payload]],
            );
        }

        if (Status::OK !== Status::tryFrom($response->getStatusCode())) {
            throw new RuntimeException(
                r(
                    text: "{action}: Request for '{client}: {user}@{backend}' libraries returned with unexpected '{status_code}' status code.",
                    context: [...$logContext, 'status_code' => $response->getStatusCode()]
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
