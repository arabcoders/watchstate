<?php

declare(strict_types=1);

namespace App\API\Backends;

use App\Libs\Attributes\Route\Get;
use App\Libs\HTTP_STATUS;
use App\Libs\Options;
use App\Libs\Traits\APITraits;
use Psr\Http\Message\ResponseInterface as iResponse;
use Psr\Http\Message\ServerRequestInterface as iRequest;

final class Index
{
    use APITraits;

    public const string URL = '%{api.prefix}/backends';

    public const array BLACK_LIST = [
        'token',
        'webhook.token',
        'options.' . Options::ADMIN_TOKEN
    ];

    #[Get(self::URL . '[/]', name: 'backends.index')]
    public function __invoke(iRequest $request): iResponse
    {
        $apiUrl = $request->getUri()->withHost('')->withPort(0)->withScheme('');
        $urlPath = $request->getUri()->getPath();

        $response = [
            'backends' => [],
            'links' => [
                'self' => (string)$apiUrl,
            ],
        ];

        foreach ($this->getBackends() as $backend) {
            $backend = array_filter(
                $backend,
                fn($key) => false === in_array($key, ['options', 'webhook'], true),
                ARRAY_FILTER_USE_KEY
            );

            $backend['links'] = [
                'self' => (string)$apiUrl->withPath(
                    parseConfigValue(\App\API\Backend\Index::URL) . '/' . $backend['name']
                ),
            ];

            $response['backends'][] = $backend;
        }

        return api_response(HTTP_STATUS::HTTP_OK, $response);
    }

}
