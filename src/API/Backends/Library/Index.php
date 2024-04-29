<?php

declare(strict_types=1);

namespace App\API\Backends\Library;

use App\API\Backends\Index as BackendsIndex;
use App\Libs\Attributes\Route\Get;
use App\Libs\Config;
use App\Libs\Exceptions\RuntimeException;
use App\Libs\HTTP_STATUS;
use App\Libs\Traits\APITraits;
use Psr\Http\Message\ResponseInterface as iResponse;
use Psr\Http\Message\ServerRequestInterface as iRequest;

final class Index
{
    use APITraits;

    #[Get(BackendsIndex::URL . '/{name:backend}/library[/]', name: 'backends.library.list')]
    public function listLibraries(iRequest $request, array $args = []): iResponse
    {
        if (null === ($name = ag($args, 'name'))) {
            return api_error('No backend was given.', HTTP_STATUS::HTTP_BAD_REQUEST);
        }

        try {
            $client = $this->getClient(name: $name);
        } catch (RuntimeException $e) {
            return api_error($e->getMessage(), HTTP_STATUS::HTTP_NOT_FOUND);
        }

        $response = [
            'type' => ag(array_flip(Config::get('supported')), $client::class),
            'libraries' => $client->listLibraries(),
            'links' => [
                'self' => (string)$request->getUri()->withHost('')->withPort(0)->withScheme(''),
                'backend' => (string)parseConfigValue(BackendsIndex::URL . "/{$name}"),
            ],
        ];

        return api_response(HTTP_STATUS::HTTP_OK, $response);
    }
}
