<?php

declare(strict_types=1);

namespace App\API\Backend;

use App\API\Backend\Index as backendIndex;
use App\Commands\Backend\Library\MismatchCommand;
use App\Libs\Attributes\Route\Get;
use App\Libs\DataUtil;
use App\Libs\Exceptions\RuntimeException;
use App\Libs\HTTP_STATUS;
use App\Libs\Options;
use App\Libs\Traits\APITraits;
use Psr\Http\Message\ResponseInterface as iResponse;
use Psr\Http\Message\ServerRequestInterface as iRequest;

final class Mismatched
{
    use APITraits;

    #[Get(backendIndex::URL . '/{name:backend}/mismatched[/[{id}[/]]]', name: 'backend.mismatched')]
    public function __invoke(iRequest $request, array $args = []): iResponse
    {
        if (null === ($name = ag($args, 'name'))) {
            return api_error('Invalid value for name path parameter.', HTTP_STATUS::HTTP_BAD_REQUEST);
        }

        $params = DataUtil::fromArray($request->getQueryParams());

        $backendOpts = $opts = $list = [];

        if ($params->get('timeout')) {
            $backendOpts = ag_set($backendOpts, 'client.timeout', (float)$params->get('timeout'));
        }

        if ($params->get('raw')) {
            $opts[Options::RAW_RESPONSE] = true;
        }

        $percentage = (float)$params->get('percentage', MismatchCommand::DEFAULT_PERCENT);
        $method = $params->get('method', MismatchCommand::METHODS[0]);

        if (false === in_array($method, MismatchCommand::METHODS, true)) {
            return api_error('Invalid comparison method.', HTTP_STATUS::HTTP_BAD_REQUEST);
        }

        try {
            $client = $this->getClient(name: $name, config: $backendOpts);
        } catch (RuntimeException $e) {
            return api_error($e->getMessage(), HTTP_STATUS::HTTP_NOT_FOUND);
        }

        $ids = [];
        if (null !== ($id = ag($args, 'id'))) {
            $ids[] = $id;
        } else {
            foreach ($client->listLibraries() as $library) {
                if (false === (bool)ag($library, 'supported') || true === (bool)ag($library, 'ignored')) {
                    continue;
                }
                $ids[] = ag($library, 'id');
            }
        }

        foreach ($ids as $libraryId) {
            foreach ($client->getLibrary(id: $libraryId, opts: $opts) as $item) {
                $processed = MismatchCommand::compare(item: $item, method: $method);

                if (empty($processed) || $processed['percent'] >= $percentage) {
                    continue;
                }

                $list[] = $processed;
            }
        }
        return api_response(HTTP_STATUS::HTTP_OK, $list);
    }
}
