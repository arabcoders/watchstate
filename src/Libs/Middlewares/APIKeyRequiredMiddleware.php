<?php

declare(strict_types=1);

namespace App\Libs\Middlewares;

use App\API\Backends\AccessToken;
use App\API\System\AutoConfig;
use App\API\System\HealthCheck;
use App\Libs\Config;
use App\Libs\Enums\Http\Method;
use App\Libs\Enums\Http\Status;
use Psr\Http\Message\ResponseInterface as iResponse;
use Psr\Http\Message\ServerRequestInterface as iRequest;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface as iHandler;

final class APIKeyRequiredMiddleware implements MiddlewareInterface
{
    public const string KEY_NAME = 'apikey';

    /**
     * Public routes that are accessible without an API key. and must remain open.
     */
    private const array PUBLIC_ROUTES = [
        HealthCheck::URL,
        AutoConfig::URL,
        AccessToken::URL,
    ];

    /**
     * Routes that follow the open route policy. However, those routes are subject to user configuration.
     */
    private const array OPEN_ROUTES = [
        '/webhook',
        '%{api.prefix}/player/'
    ];

    public function process(iRequest $request, iHandler $handler): iResponse
    {
        if (true === (bool)$request->getAttribute('INTERNAL_REQUEST')) {
            return $handler->handle($request);
        }

        if (Method::OPTIONS === Method::from($request->getMethod())) {
            return $handler->handle($request);
        }

        $requestPath = rtrim($request->getUri()->getPath(), '/');

        $openRoutes = self::PUBLIC_ROUTES;
        if (false === (bool)Config::get('api.secure', false)) {
            $openRoutes = array_merge($openRoutes, self::OPEN_ROUTES);
        }

        foreach ($openRoutes as $route) {
            $route = rtrim(parseConfigValue($route), '/');
            if (true === str_starts_with($requestPath, $route) || true === str_ends_with($requestPath, $route)) {
                return $handler->handle($request);
            }
        }

        $tokens = $this->parseTokens($request);

        if (count($tokens) < 1) {
            return api_error('API key is required to access the API.', Status::BAD_REQUEST);
        }

        foreach ($tokens as $token) {
            if (true === $this->validate($token)) {
                return $handler->handle($request);
            }
        }

        return api_error('incorrect API key.', Status::FORBIDDEN);
    }

    private function validate(?string $token): bool
    {
        if (empty($token) || !($storedKey = Config::get('api.key')) || empty($storedKey)) {
            return false;
        }

        return hash_equals($storedKey, $token);
    }

    private function parseTokens(iRequest $request): array
    {
        $tokens = [];

        if (true === $request->hasHeader('x-' . self::KEY_NAME)) {
            $tokens['header'] = $request->getHeaderLine('x-' . self::KEY_NAME);
        }

        if (true === ag_exists($request->getQueryParams(), self::KEY_NAME)) {
            $tokens['param'] = ag($request->getQueryParams(), self::KEY_NAME);
        }

        $auth = $request->getHeaderLine('Authorization');
        if (!empty($auth)) {
            [$type, $key] = explode(' ', $auth, 2);
            if (true === in_array(strtolower($type), ['bearer', 'token'])) {
                $tokens['auth'] = trim($key);
            }
        }

        return array_map(fn($val) => rawurldecode($val), array_values(array_unique(array_filter($tokens))));
    }
}
