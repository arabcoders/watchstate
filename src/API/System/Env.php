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

    private EnvFile $envFile;

    public function __construct()
    {
        $this->envFile = (new EnvFile(file: Config::get('path') . '/config/.env', create: true));
    }

    #[Get(self::URL . '[/]', name: 'system.env')]
    public function envList(iRequest $request): iResponse
    {
        $spec = require __DIR__ . '/../../../config/env.spec.php';

        foreach ($spec as &$info) {
            if (!$this->envFile->has($info['key'])) {
                continue;
            }
            $info['value'] = $this->envFile->get($info['key']);
        }

        return api_response(HTTP_STATUS::HTTP_OK, [
            'data' => $spec,
            'file' => Config::get('path') . '/config/.env',
        ]);
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

        if (false === $this->envFile->has($key)) {
            return api_error(r("Key '{key}' is not set.", ['key' => $key]), HTTP_STATUS::HTTP_NOT_FOUND);
        }

        return api_response(HTTP_STATUS::HTTP_OK, [
            'key' => $key,
            'value' => $this->envFile->get($key),
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
            $this->envFile->remove($key);
        } else {
            $params = DataUtil::fromRequest($request);
            if (null === ($value = $params->get('value', null))) {
                return api_error(r("No value was provided for '{key}'.", [
                    'key' => $key,
                ]), HTTP_STATUS::HTTP_BAD_REQUEST);
            }

            $this->envFile->set($key, $value);
        }

        $this->envFile->persist();

        return api_response(HTTP_STATUS::HTTP_OK, [
            'key' => $key,
            'value' => $this->envFile->get($key, fn() => env($key)),
        ]);
    }
}
