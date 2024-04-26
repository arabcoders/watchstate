<?php

declare(strict_types=1);

namespace App\API\System;

use App\Commands\System\EnvCommand;
use App\Libs\Attributes\Route\Get;
use App\Libs\Attributes\Route\Route;
use App\Libs\Config;
use App\Libs\DataUtil;
use App\Libs\EnvFile;
use App\Libs\HTTP_STATUS;
use App\Libs\Uri;
use Psr\Http\Message\ResponseInterface as iResponse;
use Psr\Http\Message\ServerRequestInterface as iRequest;
use Throwable;

final class Env
{
    public const string URL = '%{api.prefix}/system/env';

    private const array BLACKLIST = [
        'WS_API_KEY'
    ];

    private const array BLACKLIST_PARSE_URL = [
        'WS_CACHE_URL' => [
            'password',
        ],
    ];

    #[Get(self::URL . '[/]', name: 'system.env')]
    public function envList(iRequest $request): iResponse
    {
        $response = [
            'data' => [],
            'links' => [
                'self' => (string)$request->getUri()->withHost('')->withPort(0)->withScheme(''),
            ],
        ];

        foreach ($_ENV as $key => $val) {
            if (false === str_starts_with($key, 'WS_') && $key !== 'HTTP_PORT') {
                continue;
            }

            try {
                if (array_key_exists($key, self::BLACKLIST_PARSE_URL)) {
                    $val = new Uri($val);
                    $query = $val->getQuery();
                    $auth = $val->getUserInfo();
                    if (!empty($auth) && str_contains($auth, ':')) {
                        $val = $val->withUserInfo(before($auth, ':'), '__hidden__');
                    }
                    if (!empty($query)) {
                        parse_str($query, $q);
                        foreach ($q ?? [] as $k => $v) {
                            if (false === in_array(strtolower($k), self::BLACKLIST_PARSE_URL[$key], true)) {
                                continue;
                            }
                            $q[$k] = '__hidden__';
                        }
                        $val = $val->withQuery(http_build_query($q));
                    }
                    $val = (string)$val;
                }
            } catch (Throwable) {
            }

            if (in_array($key, self::BLACKLIST, true)) {
                $val = '__hidden__';
            }

            $response['data'][] = [
                'key' => $key,
                'value' => $val,
                'urls' => [
                    'self' => (string)$request->getUri()->withPath(parseConfigValue(self::URL . '/' . $key)),
                ],
            ];
        }

        return api_response(HTTP_STATUS::HTTP_OK, $response);
    }

    #[Get(self::URL . '/{key}[/]', name: 'system.env.view')]
    public function envView(iRequest $request, array $args = []): iResponse
    {
        $key = strtoupper((string)ag($args, 'key', ''));
        if (empty($key)) {
            return api_error('Invalid value for key path parameter.', HTTP_STATUS::HTTP_BAD_REQUEST);
        }

        if (false === str_starts_with($key, 'WS_') && false === in_array($key, EnvCommand::EXEMPT_KEYS)) {
            return api_error(r("Invalid key '{key}' was given.", ['key' => $key]), HTTP_STATUS::HTTP_BAD_REQUEST);
        }

        $val = env($key, '_not_set');

        if ('_not_set' === $val) {
            return api_error(r("Key '{key}' is not set.", ['key' => $key]), HTTP_STATUS::HTTP_NOT_FOUND);
        }

        return api_response(HTTP_STATUS::HTTP_OK, [
            'key' => $key,
            'value' => $val,
        ]);
    }

    #[Route(['POST', 'DELETE'], self::URL . '/{key}[/]', name: 'system.env.update')]
    public function envUpdate(iRequest $request, array $args = []): iResponse
    {
        $key = strtoupper((string)ag($args, 'key', ''));

        if (empty($key)) {
            return api_error('Invalid value for key path parameter.', HTTP_STATUS::HTTP_BAD_REQUEST);
        }

        if (false === str_starts_with($key, 'WS_')) {
            return api_error(r("Invalid key '{key}' was given.", ['key' => $key]), HTTP_STATUS::HTTP_BAD_REQUEST);
        }

        $envfile = (new EnvFile(file: Config::get('path') . '/config/.env', create: true));

        if ('DELETE' === $request->getMethod()) {
            $envfile->remove($key);
        } else {
            $params = DataUtil::fromRequest($request);
            if (null === ($value = $params->get('value', null))) {
                return api_error(r("No value was provided for '{key}'.", [
                    'key' => $key,
                ]), HTTP_STATUS::HTTP_BAD_REQUEST);
            }

            $envfile->set($key, $value);
        }

        $envfile->persist();

        return api_response(HTTP_STATUS::HTTP_OK, [
            'key' => $key,
            'value' => env($key, fn() => $envfile->get($key)),
        ]);
    }
}
