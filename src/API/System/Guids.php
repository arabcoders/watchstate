<?php

declare(strict_types=1);

namespace App\API\System;

use App\Libs\Attributes\Route\Delete;
use App\Libs\Attributes\Route\Get;
use App\Libs\Attributes\Route\Put;
use App\Libs\Config;
use App\Libs\ConfigFile;
use App\Libs\Container;
use App\Libs\DataUtil;
use App\Libs\Enums\Http\Status;
use App\Libs\Guid;
use Psr\Http\Message\ResponseInterface as iResponse;
use Psr\Http\Message\ServerRequestInterface as iRequest;
use Psr\Log\LoggerInterface;

final class Guids
{
    public const string URL = '%{api.prefix}/system/guids';

    #[Get(self::URL . '[/]', name: 'system.guids')]
    public function index(): iResponse
    {
        $list = [];

        Guid::setLogger(Container::get(LoggerInterface::class));
        $validator = Guid::getValidators();

        foreach (Guid::getSupported() as $guid => $type) {
            $item = [
                'guid' => after($guid, 'guid_'),
                'type' => $type,
                'validator' => ag($validator, $guid, fn() => new \stdClass()),
            ];

            $list[] = $item;
        }

        return api_response(Status::OK, $list);
    }

    #[Get(self::URL . '/custom[/]', name: 'system.guids.custom')]
    public function custom(): iResponse
    {
        return api_response(Status::OK, $this->getData());
    }

    #[Put(self::URL . '/custom[/]', name: 'system.guids.custom.guid.add')]
    public function custom_guid_add(iRequest $request): iResponse
    {
        $params = DataUtil::fromRequest($request);

        return api_response(Status::OK, $request->getParsedBody());
    }

    #[Delete(self::URL . '/custom/{index:number}[/]', name: 'system.guids.custom.guid.remove')]
    public function custom_guid_remove(iRequest $request): iResponse
    {
        return api_response(Status::OK, $request->getParsedBody());
    }

    #[Get(self::URL . '/custom/{client:word}[/]', name: 'system.guids.custom.client')]
    public function custom_client(string $client): iResponse
    {
        if (false === array_key_exists($client, Config::get('supported', []))) {
            return api_error('Client name is unsupported or incorrect.', Status::NOT_FOUND);
        }

        return api_response(Status::OK, ag($this->getData(), $client, []));
    }

    #[Put(self::URL . '/custom/{client:word}[/]', name: 'system.guids.custom.client.add')]
    public function custom_client_guid_add(iRequest $request): iResponse
    {
        return api_response(Status::OK, $request->getParsedBody());
    }

    #[Delete(self::URL . '/custom/{client:word}/{index:number}[/]', name: 'system.guids.custom.client.remove')]
    public function custom_client_guid_remove(iRequest $request): iResponse
    {
        return api_response(Status::OK, $request->getParsedBody());
    }

    #[Get(self::URL . '/custom/{client:word}/{index:number}[/]', name: 'system.guids.custom.client.guid.view')]
    public function custom_client_guid_view(string $client, string $index): iResponse
    {
        if (false === array_key_exists($client, Config::get('supported', []))) {
            return api_error('Client name is unsupported or incorrect.', Status::NOT_FOUND);
        }

        if (null === ($data = ag($this->getData(), "{$client}.{$index}"))) {
            return api_error(r("The client '{client}' index '{index}' is not found.", [
                'client' => $client,
                'index' => $index,
            ]), Status::NOT_FOUND);
        }

        return api_response(Status::OK, $data);
    }

    private function getData(): array
    {
        $file = Config::get('guid.file');

        $guids = [
            'version' => Config::get('guid.version'),
            'guids' => [],
        ];

        foreach (array_keys(Config::get('supported', [])) as $name) {
            $guids[$name] = [];
        }

        if (false === file_exists($file)) {
            return $guids;
        }

        foreach (ConfigFile::open($file, 'yaml')->getAll() as $name => $guid) {
            $guids[strtolower($name)] = $guid;
        }

        return $guids;
    }
}
