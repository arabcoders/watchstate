<?php

declare(strict_types=1);

namespace App\API\Backends;

use App\Libs\Attributes\Route\Route;
use App\Libs\DataUtil;
use App\Libs\Enums\Http\Status;
use App\Libs\Exceptions\InvalidArgumentException;
use App\Libs\Traits\APITraits;
use Psr\Http\Message\ResponseInterface as iResponse;
use Psr\Http\Message\ServerRequestInterface as iRequest;

final class UUid
{
    use APITraits;

    #[Route(['GET', 'POST'], Index::URL . '/uuid/{type}[/]', name: 'backends.get.unique_id')]
    public function __invoke(iRequest $request, array $args = []): iResponse
    {
        if (null === ($type = ag($args, 'type'))) {
            return api_error('Invalid value for type path parameter.', Status::BAD_REQUEST);
        }

        try {
            $client = $this->getBasicClient($type, DataUtil::fromRequest($request, true));

            $info = $client->getInfo();

            return api_response(Status::OK, [
                'type' => strtolower((string)ag($info, 'type')),
                'identifier' => ag($info, 'identifier'),
            ]);
        } catch (InvalidArgumentException $e) {
            return api_error($e->getMessage(), Status::BAD_REQUEST);
        } catch (\Throwable $e) {
            return api_error($e->getMessage(), Status::INTERNAL_SERVER_ERROR);
        }
    }
}
