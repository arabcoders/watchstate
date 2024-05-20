<?php

declare(strict_types=1);

namespace App\Libs\Middlewares;

use App\API\System\HealthCheck;
use App\Libs\Config;
use App\Libs\HTTP_STATUS;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Random\RandomException;

final class APIKeyRequiredMiddleware implements MiddlewareInterface
{
    public const string KEY_NAME = 'apikey';

    /**
     * Public routes that are accessible without an API key. and must remain open.
     */
    private const array PUBLIC_ROUTES = [
        HealthCheck::URL,
    ];

    /**
     * Routes that follow the open route policy. However, those routes are subject to user configuration.
     */
    private const array OPEN_ROUTES = [
        '/webhook'
    ];

    /**
     * @throws RandomException if random_bytes() fails
     */
    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        if ('OPTIONS' === $request->getMethod() || true === (bool)$request->getAttribute('INTERNAL_REQUEST', false)) {
            return $handler->handle($request->withoutAttribute('INTERNAL_REQUEST'));
        }

        $requestPath = rtrim($request->getUri()->getPath(), '/');

        $openRoutes = self::PUBLIC_ROUTES;
        if (false === (bool)Config::get('api.secure')) {
            $openRoutes = array_merge($openRoutes, self::OPEN_ROUTES);
        }

        foreach ($openRoutes as $route) {
            $route = rtrim(parseConfigValue($route), '/');
            if (true === str_starts_with($requestPath, $route) || true === str_ends_with($requestPath, $route)) {
                return $handler->handle($request);
            }
        }

        $headerApiKey = $request->getHeaderLine('x-' . self::KEY_NAME);

        if (!empty($headerApiKey)) {
            $apikey = $headerApiKey;
        } elseif (null !== ($headerApiKey = $this->parseAuthorization($request->getHeaderLine('Authorization')))) {
            $apikey = $headerApiKey;
        } else {
            $apikey = (string)ag($request->getQueryParams(), self::KEY_NAME, '');
        }

        if (empty($apikey)) {
            return api_error(
                'API key is required to access the API.',
                HTTP_STATUS::HTTP_BAD_REQUEST,
                reason: 'API key is required to access the API.',
            );
        }

        if (true === $this->validateKey(rawurldecode($apikey))) {
            return $handler->handle($request);
        }

        return api_error('API key is incorrect.', HTTP_STATUS::HTTP_FORBIDDEN, reason: 'API key is incorrect.');
    }

    /**
     * @throws RandomException if random_bytes() fails
     */
    private function validateKey(?string $token): bool
    {
        if (empty($token)) {
            return false;
        }

        // -- we generate random key if not set, to prevent timing attacks or unauthorized access.
        $storedKey = getValue(Config::get('api.key'));

        if (empty($storedKey)) {
            $storedKey = bin2hex(random_bytes(16));
        }

        return hash_equals(getValue($storedKey), $token);
    }

    private function parseAuthorization(string $header): null|string
    {
        if (empty($header)) {
            return null;
        }

        $headerLower = strtolower(trim($header));
        if (true === str_starts_with($headerLower, 'bearer')) {
            return trim(substr($header, 6));
        }

        if (false === str_starts_with($headerLower, 'basic')) {
            return null;
        }

        /** @phpstan-ignore-next-line */
        if (false === ($decoded = base64_decode(substr($header, 6)))) {
            return null;
        }

        if (false === str_contains($decoded, ':')) {
            return null;
        }

        [, $password] = explode(':', $decoded, 2);

        return empty($password) ? null : $password;
    }

}
