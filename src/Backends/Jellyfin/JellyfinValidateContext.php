<?php

declare(strict_types=1);

namespace App\Backends\Jellyfin;

use App\Backends\Common\CommonTrait;
use App\Backends\Common\Context;
use App\Backends\Jellyfin\Action\GetUser;
use App\Libs\Container;
use App\Libs\Enums\Http\Method;
use App\Libs\Enums\Http\Status;
use App\Libs\Exceptions\Backends\InvalidContextException;
use JsonException;
use Symfony\Contracts\HttpClient\Exception\ClientExceptionInterface;
use Symfony\Contracts\HttpClient\Exception\HttpExceptionInterface;
use Symfony\Contracts\HttpClient\Exception\RedirectionExceptionInterface;
use Symfony\Contracts\HttpClient\Exception\ServerExceptionInterface;
use Symfony\Contracts\HttpClient\Exception\TransportExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface as iHttp;
use Symfony\Contracts\HttpClient\ResponseInterface as iResponse;

class JellyfinValidateContext
{
    use CommonTrait;

    /**
     * @param iHttp&\App\Libs\Extends\HttpClient $http
     */
    public function __construct(
        private readonly iHttp $http,
    ) {}

    /**
     * Validate backend context.
     *
     * @param Context $context Backend context.
     * @throws InvalidContextException on failure.
     */
    public function __invoke(Context $context): bool
    {
        $data = json_decode($this->validateUrl($context), true);
        $backendId = ag($data, 'Id');

        if (empty($backendId)) {
            throw new InvalidContextException(r('Failed to get backend id. Check {client} logs for errors.', [
                'client' => $context->clientName,
            ]));
        }

        if (null !== $context->backendId && $backendId !== $context->backendId) {
            throw new InvalidContextException(
                r("Backend id mismatch. Expected '{expected}', server responded with '{actual}'.", [
                    'expected' => $context->backendId,
                    'actual' => $backendId,
                ]),
            );
        }

        $action = Container::get(GetUser::class)($context);
        if ($action->hasError()) {
            $ex = new InvalidContextException(r('Failed to get user info. {error}', [
                'error' => $action->error->format(),
            ]));
            $ex->setContext($action->error->context);

            throw $ex;
        }

        if (ag($action->response, 'id') !== $context->backendUser) {
            throw new InvalidContextException(
                r("Expected user id to be '{uid}' but the server responded with '{remote_id}'.", [
                    'uid' => $context->backendUser,
                    'remote_id' => ag($action->response, 'id'),
                ]),
            );
        }

        return true;
    }

    /**
     * Validate backend url.
     *
     * @param Context $context
     *
     * @return string
     * @throws InvalidContextException
     */
    private function validateUrl(Context $context): string
    {
        $url = $context->backendUrl->withPath('/system/Info');

        try {
            $request = $this->http->request(
                method: Method::GET,
                url: (string) $url,
                options: array_replace_recursive($context->getHttpOptions(), [
                    'headers' => [
                        'Accept' => 'application/json',
                        'X-MediaBrowser-Token' => $context->backendToken,
                    ],
                ]),
            );

            if (Status::UNAUTHORIZED === Status::tryFrom($request->getStatusCode())) {
                throw $this->validationHttpException(
                    message: 'Backend responded with 401. Most likely means token is invalid.',
                    response: $request,
                    url: (string) $url,
                );
            }

            if (Status::NOT_FOUND === Status::tryFrom($request->getStatusCode())) {
                throw $this->validationHttpException(
                    message: 'Backend responded with 404. Most likely means url is incorrect.',
                    response: $request,
                    url: (string) $url,
                );
            }

            $body = $request->getContent(true);

            try {
                $data = json_decode($body, true, flags: JSON_THROW_ON_ERROR | JSON_INVALID_UTF8_IGNORE);
            } catch (JsonException $e) {
                throw $this->invalidPayloadException(
                    message: 'Backend returned a non-JSON response from /system/Info. This usually means the URL points to the web UI/root instead of the API endpoint.',
                    response: $request,
                    url: (string) $url,
                    body: $body,
                    previous: $e,
                );
            }

            if (false === is_array($data)) {
                throw $this->invalidPayloadException(
                    message: 'Backend returned an invalid JSON payload from /system/Info.',
                    response: $request,
                    url: (string) $url,
                    body: $body,
                );
            }

            return $body;
        } catch (TransportExceptionInterface $e) {
            throw new InvalidContextException(r('Failed to connect to backend. {error}', ['error' => $e->getMessage()]), previous: $e);
        } catch (ClientExceptionInterface $e) {
            throw $this->httpException('Got non 200 response.', $e, (string) $url);
        } catch (RedirectionExceptionInterface $e) {
            throw $this->httpException('Redirection recursion detected.', $e, (string) $url);
        } catch (ServerExceptionInterface $e) {
            throw $this->httpException('Backend responded with 5xx error.', $e, (string) $url);
        }
    }

    /**
     * Build a context-rich backend validation exception from an HTTP exception.
     */
    private function httpException(string $message, HttpExceptionInterface $e, string $url): InvalidContextException
    {
        $response = $e->getResponse();
        $body = $response->getContent(false);
        $reason = $this->getBackendResponseReason($body) ?? $e->getMessage();
        $contentType = $this->getContentType($response);

        $ex = new InvalidContextException(r('{message} Backend responded with {status_code}. {error}', [
            'message' => $message,
            'status_code' => $response->getStatusCode(),
            'error' => $reason,
        ]), previous: $e);

        $ex->setContext([
            'http' => [
                'url' => $url,
                'status_code' => $response->getStatusCode(),
            ],
            'response' => [
                'headers' => $response->getHeaders(false),
                'content_type' => $contentType,
                'body' => $body,
                'reason' => $reason,
            ],
        ]);

        return $ex;
    }

    /**
     * Build a validation exception from a concrete HTTP response.
     */
    private function validationHttpException(string $message, iResponse $response, string $url): InvalidContextException
    {
        $body = $response->getContent(false);
        $reason = $this->getBackendResponseReason($body) ?? $message;
        $contentType = $this->getContentType($response);

        $ex = new InvalidContextException($message);
        $ex->setContext([
            'http' => [
                'url' => $url,
                'status_code' => $response->getStatusCode(),
            ],
            'response' => [
                'headers' => $response->getHeaders(false),
                'content_type' => $contentType,
                'body' => $body,
                'reason' => $reason,
            ],
        ]);

        return $ex;
    }

    /**
     * Build a validation exception for unexpected success payloads.
     */
    private function invalidPayloadException(
        string $message,
        iResponse $response,
        string $url,
        string $body,
        ?\Throwable $previous = null,
    ): InvalidContextException {
        $reason = $this->getBackendResponseReason($body) ?? 'Expected JSON payload but received a different response shape.';
        $contentType = $this->getContentType($response);

        $ex = new InvalidContextException(
            r('{message} Response content-type was {content_type}.', [
                'message' => $message,
                'content_type' => $contentType ?? 'unknown',
            ]),
            previous: $previous,
        );

        $ex->setContext([
            'http' => [
                'url' => $url,
                'status_code' => $response->getStatusCode(),
            ],
            'response' => [
                'headers' => $response->getHeaders(false),
                'content_type' => $contentType,
                'body' => $body,
                'reason' => $reason,
            ],
        ]);

        return $ex;
    }

    /**
     * Extract response content type from headers.
     */
    private function getContentType(iResponse $response): ?string
    {
        $contentType = null;

        foreach ($response->getHeaders(false) as $name => $values) {
            if ('content-type' !== strtolower((string) $name)) {
                continue;
            }

            $contentType = is_array($values) ? $values[0] ?? null : $values;
            break;
        }

        return is_string($contentType) && '' !== trim($contentType) ? trim($contentType) : null;
    }
}
