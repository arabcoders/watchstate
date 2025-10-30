<?php

declare(strict_types=1);

namespace App\Backends\Plex;

use App\Backends\Common\Context;
use App\Backends\Plex\Action\GetUser;
use App\Libs\Container;
use App\Libs\Enums\Http\Status;
use App\Libs\Exceptions\Backends\InvalidContextException;
use App\Libs\Options;
use Symfony\Contracts\HttpClient\Exception\ClientExceptionInterface;
use Symfony\Contracts\HttpClient\Exception\RedirectionExceptionInterface;
use Symfony\Contracts\HttpClient\Exception\ServerExceptionInterface;
use Symfony\Contracts\HttpClient\Exception\TransportExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface as iHttp;

final readonly class PlexValidateContext
{
    public function __construct(private iHttp $http)
    {
    }

    /**
     * Validate backend context.
     *
     * @param Context $context Backend context.
     * @throws InvalidContextException on failure.
     */
    public function __invoke(Context $context): bool
    {
        $response = $this->validateUrl($context);
        $data = ag(json_decode($response, true), 'MediaContainer', []);
        $backendId = ag($data, 'machineIdentifier');

        if (empty($backendId)) {
            throw new InvalidContextException('Failed to get backend id.');
        }

        if (null !== $context->backendId && $backendId !== $context->backendId) {
            throw new InvalidContextException(
                r("Backend id mismatch. Expected '{expected}', server responded with '{actual}'.", [
                    'expected' => $context->backendId,
                    'actual' => $backendId,
                ])
            );
        }

        $action = Container::get(GetUser::class)($context);
        if ($action->hasError()) {
            throw new InvalidContextException(r('Failed to get user info. {error}', [
                'error' => $action->error->format()
            ]));
        }

        $userId = ag($action->response, 'id');
        if (empty($userId)) {
            throw new InvalidContextException('Failed to get user id.');
        }

        if ((string)$context->backendUser !== (string)$userId) {
            throw new InvalidContextException(
                r("User id mismatch. Expected '{expected}', server responded with '{actual}'.", [
                    'expected' => $context->backendUser,
                    'actual' => $userId,
                ])
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
            $url = $context->backendUrl->withPath('/');

            if (null !== ($pin = ag($context->options, Options::PLEX_USER_PIN))) {
                $url = $url->withQuery(http_build_query(['pin' => (string)$pin]));
            }

            $request = $this->http->request(
                method: 'GET',
                url: (string)$url,
                options: array_replace_recursive($context->backendHeaders, [
                    'headers' => [
                        'Accept' => 'application/json',
                        'X-Plex-Token' => $context->backendToken,
                    ],
                ])
            );

            if (Status::UNAUTHORIZED === Status::tryFrom($request->getStatusCode())) {
                throw new InvalidContextException('Backend responded with 401. Most likely means token is invalid.');
            }

            if (Status::NOT_FOUND === Status::tryFrom($request->getStatusCode())) {
                throw new InvalidContextException('Backend responded with 404. Most likely means url is incorrect.');
            }

            return $request->getContent(true);
        } catch (TransportExceptionInterface $e) {
            throw new InvalidContextException(r('Failed to connect to backend. {error}', [
                'error' => $e->getMessage()
            ]), previous: $e);
        } catch (ClientExceptionInterface $e) {
            throw new InvalidContextException(r('Got non 200 response. {error}', [
                'error' => $e->getMessage()
            ]), previous: $e);
        } catch (RedirectionExceptionInterface $e) {
            throw new InvalidContextException(r('Redirection recursion detected. {error}', [
                'error' => $e->getMessage()
            ]), previous: $e);
        } catch (ServerExceptionInterface $e) {
            throw new InvalidContextException(r('Backend responded with 5xx error. {error}', [
                'error' => $e->getMessage()
            ]), previous: $e);
        }
    }
}
