<?php

declare(strict_types=1);

namespace App\API\System;

use App\Libs\Attributes\Route\Get;
use App\Libs\Attributes\Route\Route;
use App\Libs\Config;
use App\Libs\DataUtil;
use App\Libs\EnvFile;
use App\Libs\Exceptions\InvalidArgumentException;
use App\Libs\HTTP_STATUS;
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

            $info['value'] = $this->envFile->get($info['key']);
        }

        $this->envSpec = $spec;
    }

    #[Get(self::URL . '[/]', name: 'system.env')]
    public function envList(iRequest $request): iResponse
    {
        $list = [];

        foreach ($this->envSpec as $info) {
            if (array_key_exists('validate', $info)) {
                unset($info['validate']);
            }
            $list[] = $info;
        }

        return api_response(HTTP_STATUS::HTTP_OK, [
            'data' => $list,
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

        $spec = $this->getSpec($key);

        if (empty($spec)) {
            return api_error(r("Invalid key '{key}' was given.", ['key' => $key]), HTTP_STATUS::HTTP_BAD_REQUEST);
        }

        if (false === $this->envFile->has($key)) {
            return api_error(r("Key '{key}' is not set.", ['key' => $key]), HTTP_STATUS::HTTP_NOT_FOUND);
        }

        return api_response(HTTP_STATUS::HTTP_OK, [
            'key' => $key,
            'value' => $this->envFile->get($key),
            'description' => ag($spec, 'description'),
            'type' => ag($spec, 'type'),
        ]);
    }

    #[Route(['POST', 'DELETE'], self::URL . '/{key}[/]', name: 'system.env.update')]
    public function envUpdate(iRequest $request, array $args = []): iResponse
    {
        $key = strtoupper((string)ag($args, 'key', ''));
        if (empty($key)) {
            return api_error('Invalid value for key path parameter.', HTTP_STATUS::HTTP_BAD_REQUEST);
        }

        $spec = $this->getSpec($key);

        if (empty($spec)) {
            return api_error(r("Invalid key '{key}' was given.", ['key' => $key]), HTTP_STATUS::HTTP_BAD_REQUEST);
        }

        if ('DELETE' === $request->getMethod()) {
            $this->envFile->remove($key)->persist();

            return api_response(HTTP_STATUS::HTTP_OK, [
                'key' => $key,
                'value' => $this->envFile->get($key, fn() => env($key)),
                'description' => ag($spec, 'description'),
                'type' => ag($spec, 'type'),
            ]);
        }

        $params = DataUtil::fromRequest($request);

        if (null === ($value = $params->get('value', null))) {
            return api_error(r("No value was provided for '{key}'.", [
                'key' => $key,
            ]), HTTP_STATUS::HTTP_BAD_REQUEST);
        }

        if ($value === $this->envFile->get($key)) {
            return api_response(HTTP_STATUS::HTTP_NOT_MODIFIED);
        }

        $value = (string)$value;

        // -- check if the string contains space but not quoted.
        // symfony/dotenv throws an exception if the value contains a space but not quoted.
        if (str_contains($value, ' ') && (!str_starts_with($value, '"') || !str_ends_with($value, '"'))) {
            return api_error(r("The value for '{key}' must be \"quoted\", as it contains a space.", [
                'key' => $key,
            ]), HTTP_STATUS::HTTP_BAD_REQUEST);
        }

        try {
            if (false === $this->checkValue($spec, $value)) {
                throw new InvalidArgumentException(r("Invalid value for '{key}'.", ['key' => $key]));
            }
        } catch (InvalidArgumentException $e) {
            return api_error(r("Value validation for '{key}' failed. {error}", [
                'key' => $key,
                'error' => $e->getMessage()
            ]), HTTP_STATUS::HTTP_BAD_REQUEST);
        }

        if ('bool' === ag($spec, 'type')) {
            settype($value, 'bool');
        }

        $this->envFile->set($key, $value)->persist();

        return api_response(HTTP_STATUS::HTTP_OK, [
            'key' => $key,
            'value' => $this->envFile->get($key, fn() => env($key)),
            'description' => ag($spec, 'description'),
            'type' => ag($spec, 'type'),
        ]);
    }

    /**
     * Check if the value is valid.
     *
     * @param array $spec the specification for the key.
     * @param mixed $value the value to check.
     *
     * @return bool true if the value is valid, false otherwise.
     */
    private function checkValue(array $spec, mixed $value): bool
    {
        if (ag_exists($spec, 'validate')) {
            return (bool)$spec['validate']($value);
        }

        return true;
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
}
