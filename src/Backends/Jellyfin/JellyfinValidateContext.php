<?php

declare(strict_types=1);

namespace App\Backends\Jellyfin;

use App\Backends\Common\Context;
use App\Backends\Jellyfin\Action\GetUsersList;
use App\Libs\Container;
use App\Libs\Exceptions\Backends\InvalidContextException;
use App\Libs\HTTP_STATUS;
use Symfony\Contracts\HttpClient\Exception\ClientExceptionInterface;
use Symfony\Contracts\HttpClient\Exception\RedirectionExceptionInterface;
use Symfony\Contracts\HttpClient\Exception\ServerExceptionInterface;
use Symfony\Contracts\HttpClient\Exception\TransportExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface as iHttp;

class JellyfinValidateContext
{
    public function __construct(private readonly iHttp $http)
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
        $data = json_decode($this->validateUrl($context), true);
        $backendId = ag($data, 'Id');

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

        $action = Container::get(GetUsersList::class)($context);
        if ($action->hasError()) {
            throw new InvalidContextException(r('Failed to get user info. {error}', [
                'error' => $action->error->format()
            ]));
        }

        $found = false;
        $list = [];

        foreach ($action->response as $user) {
            $list[ag($user, 'name')] = ag($user, 'id');
            if ((string)ag($user, 'id') === (string)$context->backendUser) {
                $found = true;
                break;
            }
        }

        if (false === $found) {
            throw new InvalidContextException(
                r("User id '{uid}' was not found in list of users. '{user_list}'.", [
                    'uid' => $context->backendUser,
                    'user_list' => arrayToString($list),
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
            $url = $context->backendUrl->withPath('/system/Info');
            $request = $this->http->request('GET', (string)$url, [
                'headers' => [
                    'Accept' => 'application/json',
                    'X-MediaBrowser-Token' => $context->backendToken,
                ],
            ]);

            if (HTTP_STATUS::HTTP_UNAUTHORIZED->value === $request->getStatusCode()) {
                throw new InvalidContextException('Backend responded with 401. Most likely means token is invalid.');
            }

            if (HTTP_STATUS::HTTP_NOT_FOUND->value === $request->getStatusCode()) {
                throw new InvalidContextException('Backend responded with 404. Most likely means url is incorrect.');
            }

            return $request->getContent(true);
        } catch (TransportExceptionInterface $e) {
            throw new InvalidContextException(r('Failed to connect to backend. {error}', ['error' => $e->getMessage()]),
                previous: $e);
        } catch (ClientExceptionInterface $e) {
            throw new InvalidContextException(r('Got non 200 response. {error}', ['error' => $e->getMessage()]),
                previous: $e);
        } catch (RedirectionExceptionInterface $e) {
            throw new InvalidContextException(
                r('Redirection recursion detected. {error}', ['error' => $e->getMessage()]),
                previous: $e
            );
        } catch (ServerExceptionInterface $e) {
            throw new InvalidContextException(
                r('Backend responded with 5xx error. {error}', ['error' => $e->getMessage()]),
                previous: $e
            );
        }
    }
}
