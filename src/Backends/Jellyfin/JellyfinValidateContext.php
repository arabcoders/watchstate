<?php

declare(strict_types=1);

namespace App\Backends\Jellyfin;

use App\Backends\Common\Context;
use App\Backends\Jellyfin\Action\GetUser;
use App\Libs\Container;
use App\Libs\Enums\Http\Method;
use App\Libs\Enums\Http\Status;
use App\Libs\Exceptions\Backends\InvalidContextException;
use Symfony\Contracts\HttpClient\Exception\ClientExceptionInterface;
use Symfony\Contracts\HttpClient\Exception\RedirectionExceptionInterface;
use Symfony\Contracts\HttpClient\Exception\ServerExceptionInterface;
use Symfony\Contracts\HttpClient\Exception\TransportExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface as iHttp;

class JellyfinValidateContext
{
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
            throw new InvalidContextException(r('Failed to get user info. {error}', [
                'error' => $action->error->format(),
            ]));
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
        try {
            $url = $context->backendUrl->withPath('/system/Info');
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
                throw new InvalidContextException('Backend responded with 401. Most likely means token is invalid.');
            }

            if (Status::NOT_FOUND === Status::tryFrom($request->getStatusCode())) {
                throw new InvalidContextException('Backend responded with 404. Most likely means url is incorrect.');
            }

            return $request->getContent(true);
        } catch (TransportExceptionInterface $e) {
            throw new InvalidContextException(r('Failed to connect to backend. {error}', ['error' => $e->getMessage()]), previous: $e);
        } catch (ClientExceptionInterface $e) {
            throw new InvalidContextException(r('Got non 200 response. {error}', ['error' => $e->getMessage()]), previous: $e);
        } catch (RedirectionExceptionInterface $e) {
            throw new InvalidContextException(
                r('Redirection recursion detected. {error}', ['error' => $e->getMessage()]),
                previous: $e,
            );
        } catch (ServerExceptionInterface $e) {
            throw new InvalidContextException(
                r('Backend responded with 5xx error. {error}', ['error' => $e->getMessage()]),
                previous: $e,
            );
        }
    }
}
