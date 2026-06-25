<?php

declare(strict_types=1);

namespace App\API\Backend;

use App\Libs\APIResponse;
use App\Libs\Attributes\Route\Post;
use App\Libs\DataUtil;
use App\Libs\Enums\Http\Method;
use App\Libs\Enums\Http\Status;
use App\Libs\Exceptions\RuntimeException;
use App\Libs\Mappers\ImportInterface as iImport;
use App\Libs\Stream;
use App\Libs\Traits\APITraits;
use Psr\Http\Message\ResponseInterface as iResponse;
use Psr\Http\Message\ServerRequestInterface as iRequest;
use Psr\Http\Message\StreamInterface as iStream;
use Psr\Http\Message\UriInterface as iUri;
use Psr\Log\LoggerInterface as iLogger;
use Throwable;

/**
 * Proxies an arbitrary request to a configured backend, using the backend's stored credentials.
 *
 * The caller supplies a path (resolved against the backend URL), an HTTP method, optional query
 * parameters, optional body and optional non-auth headers. Auth headers are stripped and injected
 * exclusively by the backend context to prevent credential leakage or override.
 */
final class Proxy
{
    use APITraits;

    /**
     * Caller-supplied headers that are stripped before forwarding.
     *
     * Backend credentials must be injected exclusively by the backend context via
     * {@see \App\Backends\Common\Context::getHttpOptions()} so the frontend cannot override them.
     */
    private const array DENYLIST_HEADERS = [
        'authorization',
        'x-plex-token',
        'x-emby-token',
        'x-emby-authorization',
        'x-mediabrowser-token',
        'x-ms-authorization',
        'cookie',
        'set-cookie',
    ];

    public function __construct(
        private readonly iImport $mapper,
        private readonly iLogger $logger,
    ) {}

    #[Post(Index::URL . '/{name:backend}/proxy[/]', name: 'backend.proxy')]
    public function __invoke(iRequest $request, string $name): iResponse
    {
        try {
            $userContext = $this->getUserContext($request, $this->mapper, $this->logger);
        } catch (RuntimeException $e) {
            return api_error($e->getMessage(), Status::NOT_FOUND);
        }

        if (null === $this->getBackend(name: $name, userContext: $userContext)) {
            return api_error(r("Backend '{name}' not found.", ['name' => $name]), Status::NOT_FOUND);
        }

        $params = DataUtil::fromRequest($request);

        $methodValue = strtoupper((string) $params->get('method', 'GET'));
        $method = Method::tryFrom($methodValue);

        if (null === $method) {
            return api_error(
                r("Method '{method}' is not supported.", ['method' => $methodValue]),
                Status::BAD_REQUEST,
            );
        }

        $path = trim((string) $params->get('path', ''));

        if ('' === $path) {
            return api_error('No path was given.', Status::BAD_REQUEST);
        }

        try {
            $client = $this->getClient(name: $name, userContext: $userContext);
        } catch (RuntimeException $e) {
            return api_error($e->getMessage(), Status::NOT_FOUND);
        }

        $context = $client->getContext();

        try {
            $uri = $this->buildUri($context->backendUrl, $path, (array) $params->get('query', []));
        } catch (RuntimeException $e) {
            return api_error($e->getMessage(), Status::BAD_REQUEST);
        }

        $headers = $this->filterHeaders((array) $params->get('headers', []));

        $proxyBody = $this->resolveBody($method, $params->get('body'));

        $logContext = [
            'operation' => 'backend.proxy',
            'identity' => [
                'user' => $context->userContext->name,
                'backend' => $context->backendName,
                'client' => $context->clientName,
            ],
            'request' => [
                'method' => $method->value,
                'url' => (string) $uri,
            ],
        ];

        try {
            $apiRequest = $client->proxy(method: $method, uri: $uri, body: $proxyBody, opts: [
                'headers' => $headers,
            ]);
        } catch (Throwable $e) {
            $this->logger->error(
                message: "Proxy request to '{identity.user}@{identity.backend}' failed. {exception.message}",
                context: [...$logContext, ...exception_log($e)],
            );

            return api_response(Status::OK, [
                'request' => [
                    'method' => $method->value,
                    'url' => (string) $uri,
                    'headers' => $headers,
                ],
                'response' => [
                    'status' => Status::INTERNAL_SERVER_ERROR->value,
                    'headers' => [
                        'WS-Exception' => $e::class,
                        'WS-Error' => $e->getMessage(),
                    ],
                    'body' => $e->getMessage(),
                ],
            ]);
        }

        if (false === $apiRequest->isSuccessful()) {
            $this->logger->log(
                $apiRequest->error->level(),
                $apiRequest->error->message,
                $apiRequest->error->context,
            );

            return api_error('Backend proxy request failed.', Status::BAD_REQUEST);
        }

        $apiResponse = $apiRequest->response;

        if (!$apiResponse instanceof APIResponse) {
            $this->logger->error(
                message: "Proxy request to '{identity.user}@{identity.backend}' returned an unexpected response shape.",
                context: $logContext,
            );

            return api_error('Unexpected backend proxy response.', Status::INTERNAL_SERVER_ERROR);
        }

        $bodyString = null !== $apiResponse->stream ? (string) $apiResponse->stream : '';

        $flattenedHeaders = [];

        foreach ($apiResponse->headers as $key => $value) {
            $flattenedHeaders[$key] = is_array($value) ? implode(', ', $value) : (string) $value;
        }

        $this->logger->debug(
            message: "Proxy request to '{identity.user}@{identity.backend}' completed with status {status_code}.",
            context: [...$logContext, 'response' => ['status_code' => $apiResponse->status->value]],
        );

        return api_response(Status::OK, [
            'request' => [
                'method' => $method->value,
                'url' => (string) $uri,
                'headers' => $headers,
            ],
            'response' => [
                'status' => $apiResponse->status->value,
                'headers' => $flattenedHeaders,
                'body' => $bodyString,
            ],
        ]);
    }

    /**
     * Resolve caller-supplied path and query against the backend URL, preserving the backend host.
     *
     * @param iUri $backendUrl the configured backend URL (supplies scheme/host/port/userInfo).
     * @param string $path caller-supplied path. Must be relative; absolute URLs and protocol-relative
     *     URLs are rejected to prevent SSRF.
     * @param array<string,mixed> $query caller-supplied query parameters.
     *
     * @throws RuntimeException when the path is not safe to forward.
     */
    private function buildUri(iUri $backendUrl, string $path, array $query): iUri
    {
        if (str_contains($path, '://') || str_starts_with($path, '//')) {
            throw new RuntimeException('Absolute and protocol-relative paths are not allowed.');
        }

        $normalizedPath = '/' . ltrim($path, '/');

        $uri = $backendUrl->withPath($normalizedPath);

        if (count($query) > 0) {
            $uri = $uri->withQuery(http_build_query($this->flattenQuery($query)));
        }

        return $uri;
    }

    /**
     * Strip denylisted (auth-related) headers from the caller-supplied set.
     *
     * @param array<mixed> $headers caller-supplied headers as key/value pairs.
     *
     * @return array<string,string> the filtered headers safe to forward.
     */
    private function filterHeaders(array $headers): array
    {
        $filtered = [];

        foreach ($headers as $key => $value) {
            if (!is_string($key) || '' === trim($key)) {
                continue;
            }

            if (in_array(strtolower($key), self::DENYLIST_HEADERS, true)) {
                continue;
            }

            if (null === $value) {
                continue;
            }

            $filtered[$key] = is_string($value) ? $value : (string) $value;
        }

        return $filtered;
    }

    /**
     * Flatten nested query values into a shape http_build_query can consume safely.
     *
     * @param array<string,mixed> $query caller-supplied query parameters.
     *
     * @return array<string,string> flattened query parameters.
     */
    private function flattenQuery(array $query): array
    {
        $flat = [];

        foreach ($query as $key => $value) {
            if (null === $value || '' === $value) {
                continue;
            }

            if (is_array($value)) {
                $flat[$key] = implode(',', array_map('strval', $value));
                continue;
            }

            $flat[$key] = (string) $value;
        }

        return $flat;
    }

    /**
     * Resolve the request body into the shape expected by {@see \App\Backends\Common\ClientInterface::proxy()}.
     *
     * Array bodies are forwarded as-is (the backend Proxy action JSON-encodes them). Non-empty string
     * bodies are wrapped in a stream so non-JSON payloads (XML, form-encoded, etc.) work when the
     * caller also supplies the matching Content-Type header. An empty array signals "no body".
     *
     * @return array<mixed>|iStream
     */
    private function resolveBody(Method $method, mixed $body): array|iStream
    {
        if (in_array($method, [Method::GET, Method::HEAD], true)) {
            return [];
        }

        if (is_array($body)) {
            return $body;
        }

        if (is_string($body) && '' !== trim($body)) {
            return Stream::create($body);
        }

        return [];
    }
}
