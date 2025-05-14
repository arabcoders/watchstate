<?php

declare(strict_types=1);

namespace App\Libs\Middlewares;

use App\API\System\Auth;
use App\API\System\AutoConfig;
use App\API\System\HealthCheck;
use App\Libs\Config;
use App\Libs\Enums\Http\Method;
use App\Libs\Enums\Http\Status;
use App\Libs\TokenUtil;
use Psr\Http\Message\ResponseInterface as iResponse;
use Psr\Http\Message\ServerRequestInterface as iRequest;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface as iHandler;
use Throwable;

final class AuthorizationMiddleware implements MiddlewareInterface
{
    public const string KEY_NAME = 'apikey';

    /**
     * Public routes that are accessible without an API key. and must remain open.
     */
    private const array PUBLIC_ROUTES = [
        HealthCheck::URL,
        AutoConfig::URL,
        Auth::URL,
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
            return api_error('Authorization is required to access the API.', Status::BAD_REQUEST);
        }

        if (array_any($tokens, fn ($token, $type) => true === $this->validate($type, $token))) {
            return $handler->handle($request);
        }

        return api_error('Incorrect authorization credentials.', Status::UNAUTHORIZED);
    }

    private function validate(string $type, ?string $token): bool
    {
        if (empty($token)) {
            return false;
        }

        if ('token' === $type) {
            return $this->validateToken($token);
        }

        if (!($storedKey = Config::get('api.key')) || empty($storedKey)) {
            return false;
        }

        return hash_equals($storedKey, $token);
    }

    /**
     * Validate user token.
     *
     * @param string|null $token The token to validate.
     *
     * @return bool True if the tken is valid. False otherwise.
     */
    public static function validateToken(?string $token): bool
    {
        if (empty($token)) {
            return false;
        }

        try {
            $decoded = TokenUtil::decode($token);
        } catch (Throwable) {
            return false;
        }

        if (false === $decoded) {
            return false;
        }

        $parts = explode('.', $decoded, 2);
        if (2 !== count($parts)) {
            return false;
        }

        [$signature, $payload] = $parts;

        if (false === TokenUtil::verify($payload, $signature)) {
            return false;
        }

        try {
            $payload = json_decode($payload, true, flags: JSON_THROW_ON_ERROR);
            $rand = fn () => TokenUtil::generateSecret();
            $systemUser = (string)Config::get('system.user', $rand);
            $payloadUser = (string)ag($payload, 'username', $rand);

            if (false === hash_equals($systemUser, $payloadUser)) {
                return false;
            }

            // $version = (string)ag($payload, 'version', '');
            // $currentVersion = getAppVersion();
            // if (false === hash_equals($currentVersion, $version)) {
            //     return false;
            // }
        } catch (Throwable) {
            return false;
        }

        return true;
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

        foreach ($request->getHeader('Authorization') as $auth) {
            [$type, $value] = explode(' ', $auth, 2);
            $type = strtolower(trim($type));

            if (false === in_array($type, ['bearer', 'token'])) {
                continue;
            }

            $tokens[$type] = trim($value);
        }

        return array_unique(array_map(fn ($val) => rawurldecode($val), $tokens));
    }
}
