<?php

declare(strict_types=1);

namespace App\API\Backends;

use App\Libs\Attributes\Route\Get;
use App\Libs\Config;
use App\Libs\HTTP_STATUS;
use App\Libs\Options;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

#[Get(self::URL . '[/]', name: 'backends.index')]
final class Index
{
    public const URL = '%{api.prefix}/backends';

    public const BLACK_LIST = [
        'token',
        'webhook.token',
        'options.' . Options::ADMIN_TOKEN
    ];

    public function __invoke(ServerRequestInterface $request, array $args = []): ResponseInterface
    {
        $apiUrl = $request->getUri()->withHost('')->withPort(0)->withScheme('');
        $urlPath = $request->getUri()->getPath();

        $response = [
            'backends' => [],
            'links' => [
                'self' => (string)$apiUrl,
            ],
        ];

        foreach (self::getBackends(blacklist: true) as $backend) {
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

    public static function getBackends(string|null $name = null, bool $blacklist = false): array
    {
        $backends = [];
        foreach (Config::get('servers', []) as $backendName => $backend) {
            $backend = ['name' => $backendName, ...$backend];

            if (true === $blacklist) {
                foreach (self::BLACK_LIST as $hideValue) {
                    if (true === ag_exists($backend, $hideValue)) {
                        $backend = ag_set($backend, $hideValue, '__hidden__');
                    }
                }
            }

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
