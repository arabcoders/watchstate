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

    #[Get(self::URL . '[/]', name: 'backends')]
    public function __invoke(iRequest $request): iResponse
    {
        $list = [];

        foreach ($this->getBackends() as $backend) {
            $list[] = array_filter(
                $backend,
                fn($key) => false === in_array($key, ['options', 'webhook'], true),
                ARRAY_FILTER_USE_KEY
            );
        }

        return api_response(HTTP_STATUS::HTTP_OK, $list);
    }
}
