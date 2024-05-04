<?php

declare(strict_types=1);

namespace App\API\Backend;

use App\Libs\Attributes\Route\Get;
use App\Libs\DataUtil;
use App\Libs\Exceptions\InvalidArgumentException;
use App\Libs\HTTP_STATUS;
use App\Libs\Options;
use App\Libs\Traits\APITraits;
use Psr\Http\Message\ResponseInterface as iResponse;
use Psr\Http\Message\ServerRequestInterface as iRequest;
use Throwable;

final class Info
{
    use APITraits;

    #[Get(Index::URL . '/{name:backend}/info[/]', name: 'backends.backend.info')]
    public function backendsView(iRequest $request, array $args = []): iResponse
    {
        if (null === ($name = ag($args, 'name'))) {
            return api_error('Invalid value for id path parameter.', HTTP_STATUS::HTTP_BAD_REQUEST);
        }

        try {
            $client = $this->getClient(name: $name);
        } catch (InvalidArgumentException $e) {
            return api_error($e->getMessage(), HTTP_STATUS::HTTP_NOT_FOUND);
        }

        $opts = [];
        $params = DataUtil::fromRequest($request, true);

        if (true === (bool)$params->get('raw', false)) {
            $opts[Options::RAW_RESPONSE] = true;
        }

        try {
            $data = $client->getInfo($opts);
        } catch (Throwable $e) {
            return api_error($e->getMessage(), HTTP_STATUS::HTTP_INTERNAL_SERVER_ERROR);
        }

        $apiUrl = $request->getUri()->withHost('')->withPort(0)->withScheme('');

        $response = [
            'data' => $data,
            'links' => [
                'self' => (string)$apiUrl,
                'list' => (string)$apiUrl->withPath(parseConfigValue(Index::URL)),
            ],
        ];

        return api_response(HTTP_STATUS::HTTP_OK, $response);
    }
}
