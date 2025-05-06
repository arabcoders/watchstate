<?php

declare(strict_types=1);

namespace App\API\System;

use App\Libs\Attributes\Route\Get;
use App\Libs\Attributes\Route\Route;
use App\Libs\Config;
use App\Libs\DataUtil;
use App\Libs\Enums\Http\Status;
use App\Libs\EnvFile;
use App\Libs\Exceptions\ValidationException;
use Psr\Http\Message\ResponseInterface as iResponse;
use Psr\Http\Message\ServerRequestInterface as iRequest;

final class Env
{
    public const string URL = '%{api.prefix}/system/env';

    private EnvFile $envFile;

    private array $envSpec;

    public function __construct()
    {
        $this->envFile = (new EnvFile(file: Config::get('path') . '/config/.env', create: true));

        $spec = require __DIR__ . '/../../../config/env.spec.php';

        foreach ($spec as &$info) {
            if (!$this->envFile->has($info['key'])) {
                continue;
            }

            $info['value'] = $this->setType($info, $this->envFile->get($info['key']));
        }

        $this->envSpec = $spec;
    }

    #[Get(self::URL . '[/]', name: 'system.env')]
    public function envList(iRequest $request): iResponse
    {
        $params = DataUtil::fromRequest($request, true);

        $list = [];

        foreach ($this->envSpec as $info) {
            if (array_key_exists('validate', $info)) {
                unset($info['validate']);
            }
            $list[] = $info;
        }

        if (true === (bool)$params->get('set', false)) {
            $list = array_filter($list, fn($info) => $this->envFile->has($info['key']));
        }

        return api_response(Status::OK, [
            'data' => array_values($list),
            'file' => Config::get('path') . '/config/.env',
        ]);
    }

    #[Get(self::URL . '/{key}[/]', name: 'system.env.view')]
    public function envView(string $key): iResponse
    {
        if (empty($key)) {
            return api_error('Invalid value for key path parameter.', Status::BAD_REQUEST);
        }

        $spec = $this->getSpec($key);

        if (empty($spec)) {
            return api_error(r("Invalid key '{key}' was given.", ['key' => $key]), Status::BAD_REQUEST);
        }

        if (false === $this->envFile->has($key)) {
            return api_error(r("Key '{key}' is not set.", ['key' => $key]), Status::NOT_FOUND);
        }

        return api_response(Status::OK, [
            'key' => $key,
            'value' => $this->settype($spec, ag($spec, 'value', fn() => $this->envFile->get($key))),
            'description' => ag($spec, 'description'),
            'type' => ag($spec, 'type'),
        ]);
    }

    #[Route(['POST', 'DELETE'], self::URL . '/{key}[/]', name: 'system.env.update')]
    public function envUpdate(iRequest $request, array $args = []): iResponse
    {
        $key = strtoupper((string)ag($args, 'key', ''));
        if (empty($key)) {
            return api_error('Invalid value for key path parameter.', Status::BAD_REQUEST);
        }

        $spec = $this->getSpec($key);

        if (empty($spec)) {
            return api_error(r("Invalid key '{key}' was given.", ['key' => $key]), Status::BAD_REQUEST);
        }

        if ('DELETE' === $request->getMethod()) {
            $this->envFile->remove($key)->persist();

            return api_response(Status::OK, [
                'key' => $key,
                'value' => $this->setType($spec, ag($spec, 'value', fn() => $this->envFile->get($key))),
                'description' => ag($spec, 'description'),
                'type' => ag($spec, 'type'),
            ]);
        }

        $params = DataUtil::fromRequest($request);

        if (null === ($value = $params->get('value', null))) {
            return api_error(r("No value was provided for '{key}'.", ['key' => $key]), Status::BAD_REQUEST);
        }

        try {
            $value = $this->setType($spec, $value);

            if (true === ag_exists($spec, 'validate')) {
                $value = $spec['validate']($value, $spec);
            }
        } catch (ValidationException $e) {
            return api_error(r("Value validation for '{key}' failed. {error}", [
                'key' => $key,
                'error' => $e->getMessage()
            ]), Status::BAD_REQUEST);
        }

        $this->envFile->set($key, $value)->persist();

        return api_response(Status::OK, [
            'key' => $key,
            'value' => $value,
            'description' => ag($spec, 'description'),
            'type' => ag($spec, 'type'),
        ]);
    }

    /**
     * Get Information about the key.
     *
     * @param string $key the key to get information about.
     *
     * @return array the information about the key. Or an empty array if not found.
     */
    private function getSpec(string $key): array
    {
        foreach ($this->envSpec as $info) {
            if ($info['key'] !== $key) {
                continue;
            }
            return $info;
        }

        return [];
    }

    private function setType($spec, mixed $value): string|int|bool|float
    {
        if ('bool' === ag($spec, 'type', 'string')) {
            if (is_bool($value)) {
                return $value;
            }
            if (true === in_array(strtolower((string)$value), ['true', '1', 'yes', 'on'], true)) {
                return true;
            }

            return false;
        }

        return match (ag($spec, 'type', 'string')) {
            'int' => (int)$value,
            'float' => (float)$value,
            default => (string)$value,
        };
    }
}
