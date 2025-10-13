<?php

declare(strict_types=1);

namespace App\API\Backends;

use App\Libs\Attributes\Route\Get;
use App\Libs\Enums\Http\Status;
use App\Libs\Mappers\ImportInterface as iImport;
use App\Libs\Options;
use App\Libs\Traits\APITraits;
use Psr\Http\Message\ResponseInterface as iResponse;
use Psr\Http\Message\ServerRequestInterface as iRequest;
use Psr\Log\LoggerInterface as iLogger;

final class Index
{
    use APITraits;

    public const string URL = '%{api.prefix}/backends';

    public const array BLACK_LIST = [
        'token',
        'options.' . Options::ADMIN_TOKEN,
        'options.' . Options::PLEX_USER_PIN,
    ];

    #[Get(self::URL . '[/]', name: 'backends')]
    public function __invoke(iRequest $request, iImport $mapper, iLogger $logger): iResponse
    {
        $list = [];
        $user = $this->getUserContext($request, $mapper, $logger);
        $removedKeys = ag(include __DIR__ . '/../../../config/removed.keys.php', 'backend', []);

        foreach ($this->getBackends(userContext: $user) as $backend) {
            if (!$request->getAttribute(Options::INTERNAL_REQUEST)) {
                $item = array_filter(
                    $backend,
                    fn($key) => false === in_array($key, ['options', ...$removedKeys], true),
                    ARRAY_FILTER_USE_KEY
                );

                $item = ag_set(
                    $item,
                    'options.' . Options::IMPORT_METADATA_ONLY,
                    (bool)ag($backend, 'options.' . Options::IMPORT_METADATA_ONLY, false)
                );
                $list[] = $item;
                continue;
            }

            $list[] = $backend;
        }

        return api_response(Status::OK, $list);
    }
}
