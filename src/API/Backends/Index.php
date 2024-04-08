<?php

declare(strict_types=1);

namespace App\API\Backends;

use App\Libs\Attributes\Route\Get;
use App\Libs\Config;
use App\Libs\ConfigFile;
use App\Libs\HTTP_STATUS;
use App\Libs\Options;
use Psr\Http\Message\ResponseInterface as iResponse;
use Psr\Http\Message\ServerRequestInterface as iRequest;

final class Index
{
    public const string URL = '%{api.prefix}/backends';

    public const array BLACK_LIST = [
        'token',
        'webhook.token',
        'options.' . Options::ADMIN_TOKEN
    ];

    #[Get(self::URL . '[/]', name: 'backends.index')]
    public function backendsIndex(iRequest $request): iResponse
    {
        $apiUrl = $request->getUri()->withHost('')->withPort(0)->withScheme('');
        $urlPath = $request->getUri()->getPath();

        $response = [
            'backends' => [],
            'links' => [
                'self' => (string)$apiUrl,
            ],
        ];

        foreach (self::getBackends() as $backend) {
            $backend = array_filter(
                $backend,
                fn($key) => false === in_array($key, ['options', 'webhook'], true),
                ARRAY_FILTER_USE_KEY
            );

            $backend['links'] = [
                'self' => (string)$apiUrl->withPath($urlPath . '/' . $backend['name']),
            ];

            $response['backends'][] = $backend;
        }

        return api_response(HTTP_STATUS::HTTP_OK, $response);
    }

    #[Get(self::URL . '/{id:[a-zA-Z0-9_-]+}[/]', name: 'backends.view')]
    public function backendsView(iRequest $request, array $args = []): iResponse
    {
        if (null === ($id = ag($args, 'id'))) {
            return api_error('Invalid value for id path parameter.', HTTP_STATUS::HTTP_BAD_REQUEST);
        }

        $data = Index::getBackends(name: $id);
        if (empty($data)) {
            return api_error('Backend not found.', HTTP_STATUS::HTTP_NOT_FOUND);
        }

        $apiUrl = $request->getUri()->withHost('')->withPort(0)->withScheme('');
        $data = array_pop($data);

        $response = [
            ...$data,
            'links' => [
                'self' => (string)$apiUrl,
                'list' => (string)$apiUrl->withPath(parseConfigValue(self::URL)),
            ],
        ];

        return api_response(HTTP_STATUS::HTTP_OK, ['backend' => $response]);
    }

    private function getBackends(string|null $name = null): array
    {
        $backends = [];

        foreach (ConfigFile::open(Config::get('backends_file'), 'yaml')->getAll() as $backendName => $backend) {
            $backend = ['name' => $backendName, ...$backend];

            if (null !== ag($backend, 'import.lastSync')) {
                $backend = ag_set($backend, 'import.lastSync', makeDate(ag($backend, 'import.lastSync')));
            }

            if (null !== ag($backend, 'export.lastSync')) {
                $backend = ag_set($backend, 'export.lastSync', makeDate(ag($backend, 'export.lastSync')));
            }

            $backends[] = $backend;
        }

        if (null !== $name) {
            return array_filter($backends, fn($backend) => $backend['name'] === $name);
        }
        return $backends;
    }
}
