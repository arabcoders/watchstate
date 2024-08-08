<?php

declare(strict_types=1);

namespace App\API\Backends;

use App\Libs\Attributes\Route\Get;
use App\Libs\Enums\Http\Status;
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
            $item = array_filter(
                $backend,
                fn($key) => false === in_array($key, ['options', 'webhook'], true),
                ARRAY_FILTER_USE_KEY
            );

            $item = ag_set(
                $item,
                'options.' . Options::IMPORT_METADATA_ONLY,
                (bool)ag($backend, 'options.' . Options::IMPORT_METADATA_ONLY, false)
            );

            $list[] = $item;
        }

        return api_response(Status::OK, $list);
    }
}
