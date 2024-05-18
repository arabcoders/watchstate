<?php

declare(strict_types=1);

namespace App\API\Backends;

use App\Libs\Attributes\Route\Get;
use App\Libs\HTTP_STATUS;
use App\Libs\Traits\APITraits;
use Psr\Http\Message\ResponseInterface as iResponse;
use Psr\Http\Message\ServerRequestInterface as iRequest;

final class Spec
{
    use APITraits;

    #[Get(Index::URL . '/spec[/]', name: 'backends.spec')]
    public function __invoke(iRequest $request, array $args = []): iResponse
    {
        $specs = require __DIR__ . '/../../../config/servers.spec.php';

        $list = [];

        foreach ($specs as $spec) {
            $item = [
                'key' => $spec['key'],
                'type' => $spec['type'],
                'description' => $spec['description'],
            ];

            if (ag_exists($spec, 'choices')) {
                $item['choices'] = $spec['choices'];
            }

            $list[] = $item;
        }

        return api_response(HTTP_STATUS::HTTP_OK, $list);
    }
}
