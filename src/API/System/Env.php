<?php

declare(strict_types=1);

namespace App\API\System;

use App\Libs\Attributes\Route\Get;
use App\Libs\Attributes\Route\Route;
use App\Libs\Config;
use App\Libs\DataUtil;
use App\Libs\EnvFile;
use App\Libs\HTTP_STATUS;
use Psr\Http\Message\ResponseInterface as iResponse;
use Psr\Http\Message\ServerRequestInterface as iRequest;

final class Env
{
    public const string URL = '%{api.prefix}/system/env';

    private EnvFile $envfile;

    public function __construct()
    {
        $this->envfile = (new EnvFile(file: Config::get('path') . '/config/.env', create: true));
    }

    #[Get(self::URL . '[/]', name: 'system.env')]
    public function envList(iRequest $request): iResponse
    {
        $response = [
            'data' => [],
            'file' => Config::get('path') . '/config/.env',
            'links' => [
                'self' => (string)$request->getUri()->withHost('')->withPort(0)->withScheme(''),
            ],
        ];

        foreach ($this->envfile->getAll() as $key => $val) {
            if (false === str_starts_with($key, 'WS_')) {
                continue;
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

        if (false === str_starts_with($key, 'WS_')) {
            return api_error(r("Invalid key '{key}' was given.", ['key' => $key]), HTTP_STATUS::HTTP_BAD_REQUEST);
        }

        if (false === $this->envfile->has($key)) {
            return api_error(r("Key '{key}' is not set.", ['key' => $key]), HTTP_STATUS::HTTP_NOT_FOUND);
        }

        return api_response(HTTP_STATUS::HTTP_OK, [
            'key' => $key,
            'value' => $this->envfile->get($key),
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

        if ('DELETE' === $request->getMethod()) {
            $this->envfile->remove($key);
        } else {
            $params = DataUtil::fromRequest($request);
            if (null === ($value = $params->get('value', null))) {
                return api_error(r("No value was provided for '{key}'.", [
                    'key' => $key,
                ]), HTTP_STATUS::HTTP_BAD_REQUEST);
            }

            $this->envfile->set($key, $value);
        }

        $this->envfile->persist();

        return api_response(HTTP_STATUS::HTTP_OK, [
            'key' => $key,
            'value' => env($key, fn() => $this->envfile->get($key)),
        ]);
    }
}
