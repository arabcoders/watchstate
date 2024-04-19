<?php

declare(strict_types=1);

namespace App\API\Backends;

use App\Backends\Common\ClientInterface;
use App\Libs\Attributes\Route\Post;
use App\Libs\Config;
use App\Libs\ConfigFile;
use App\Libs\Container;
use App\Libs\DataUtil;
use App\Libs\Exceptions\Backends\InvalidContextException;
use App\Libs\HTTP_STATUS;
use App\Libs\Traits\APITraits;
use Psr\Http\Message\ResponseInterface as iResponse;
use Psr\Http\Message\ServerRequestInterface as iRequest;

final class Add
{
    use APITraits;

    #[Post(Index::URL . '[/]', name: 'backends.add')]
    public function BackendAdd(iRequest $request): iResponse
    {
        $data = DataUtil::fromArray($request->getParsedBody());

        if (null === ($type = $data->get('type'))) {
            return api_error('No type was given.', HTTP_STATUS::HTTP_BAD_REQUEST);
        }

        if (null === ($name = $data->get('name'))) {
            return api_error('No name was given.', HTTP_STATUS::HTTP_BAD_REQUEST);
        }

        $backend = $this->getBackends(name: $name);

        if (!empty($backend)) {
            return api_error(r("Backend '{backend}' already exists.", [
                'backend' => $name
            ]), HTTP_STATUS::HTTP_CONFLICT);
        }

        if (null === ($class = Config::get("supported.{$type}", null))) {
            throw api_error(r("Unexpected client type '{type}' was given.", [
                'type' => $type
            ]), HTTP_STATUS::HTTP_BAD_REQUEST);
        }

        $instance = Container::getNew($class);
        assert($instance instanceof ClientInterface, new \RuntimeException('Invalid client class.'));

        try {
            $context = $instance->fromRequest($request);
            if (false === $instance->validateContext($context)) {
                throw new InvalidContextException('Invalid context.');
            }

            $configFile = ConfigFile::open(Config::get('backends_file'), 'yaml', autoSave: false);
            $configFile->set($name, $context);
            $configFile->persist();
        } catch (InvalidContextException $e) {
            return api_error($e->getMessage(), HTTP_STATUS::HTTP_BAD_REQUEST);
        }

        $response = [
            'backends' => [],
            'links' => [],
        ];

        return api_response(HTTP_STATUS::HTTP_OK, $response);
    }
}
