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
use InvalidArgumentException;
use Psr\Http\Message\ResponseInterface as iResponse;
use Psr\Http\Message\ServerRequestInterface as iRequest;
use Psr\Log\LoggerInterface;
use Throwable;

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

        $requiredFields = [
            'name' => 'string',
            'type' => join('|', array_keys(Config::get('supported'))),
            'description' => 'string',
            'validator.pattern' => 'string',
            'validator.example' => 'string',
            'validator.tests.valid' => ['string'],
            'validator.tests.invalid' => ['string'],
        ];

        foreach ($requiredFields as $field => $type) {
            if (!$params->get($field)) {
                return api_error(r("Field '{field}' is required. And is missing from request.", [
                    'field' => $field
                ]), httpCode: Status::BAD_REQUEST, body: ['requiredFields' => $requiredFields]);
            }
        }

        try {
            if (false === str_starts_with($params->get('name'), 'guid_')) {
                $params = $params->with('name', 'guid_' . $params->get('name'));
            }
            $this->validateName($params->get('name'));
        } catch (InvalidArgumentException $e) {
            return api_error($e->getMessage(), Status::BAD_REQUEST);
        }

        $pattern = stripslashes($params->get('validator.pattern'));
        try {
            preg_match($pattern, '');
        } catch (Throwable) {
            return api_error(r("Invalid regex pattern: '{pattern}'.", ['pattern' => $pattern]), Status::BAD_REQUEST);
        }

        if (count($params->get('validator.tests.valid')) < 1) {
            return api_error('At least one valid test is required.', Status::BAD_REQUEST);
        }

        foreach ($params->get('validator.tests.valid', []) as $index => $test) {
            if (empty($test)) {
                return api_error(r("Empty value {index} - '{test}' is not allowed.", [
                    'index' => $index,
                    'test' => $test
                ]), Status::BAD_REQUEST);
            }

            if (1 === preg_match($pattern, (string)$test)) {
                continue;
            }

            return api_error(
                r("Correct value {index} - '{test}' did not match given pattern '{pattern}'.", [
                    'index' => $index,
                    'test' => $test,
                    'pattern' => $pattern,
                ]),
                Status::BAD_REQUEST
            );
        }

        if (count($params->get('validator.tests.invalid')) < 1) {
            return api_error('At least one invalid test is required.', Status::BAD_REQUEST);
        }

        foreach ($params->get('validator.tests.invalid', []) as $index => $test) {
            if (1 !== preg_match($pattern, (string)$test)) {
                continue;
            }

            return api_error(r("Incorrect value {index} - '{test}' matched given pattern '{pattern}'.", [
                'index' => $index,
                'test' => $test,
                'pattern' => $pattern
            ]), Status::BAD_REQUEST);
        }

        $data = [
            'id' => generateUUID(),
            'type' => $params->get('type'),
            'name' => $params->get('name'),
            'description' => $params->get('description'),
            'validator' => [
                'pattern' => $pattern,
                'example' => $params->get('validator.example'),
                'tests' => [
                    'valid' => $params->get('validator.tests.valid'),
                    'invalid' => $params->get('validator.tests.invalid')
                ]
            ]
        ];

        $file = ConfigFile::open(Config::get('guid.file'), 'yaml', autoSave: false, autoCreate: true, autoBackup: true);

        if (false === $file->has('guids') || false === is_array($file->get('guids'))) {
            $file->set('guids', []);
        }

        $guids = array_values($file->get('guids', []));
        $guids[] = $data;
        $file->set('guids', null)->set('guids', $guids)->persist();

        return api_response(Status::OK, $data);
    }

    #[Delete(self::URL . '/custom/{id:uuid}[/]', name: 'system.guids.custom.guid.remove')]
    public function custom_guid_remove(string $id): iResponse
    {
        $file = ConfigFile::open(Config::get('guid.file'), 'yaml', autoCreate: true, autoBackup: true);

        $data = [];
        $found = false;

        foreach ($file->get('guids', []) as $index => $guid) {
            if ($guid['id'] === $id) {
                $data = $guid;
                $file->delete('guids.' . $index);
                $found = true;
                break;
            }
        }

        if (false === $found) {
            return api_error(r("The GUID '{id}' is not found.", ['id' => $id]), Status::NOT_FOUND);
        }

        $guids = $file->get('guids', []);
        $file->set('guids', null)->set('guids', array_values($guids))->persist();

        $linkRemoved = false;
        foreach ($file->get('links', []) as $index => $link) {
            if (ag($link, 'map.to') === ag($data, 'name')) {
                $file->delete('links.' . $index);
                $linkRemoved = true;
            }
        }

        if (true === $linkRemoved) {
            $links = $file->get('links', []);
            $file->set('links', null)->set('links', array_values($links));
        }

        $file->persist();

        return api_response(Status::OK, $data);
    }

    #[Get(self::URL . '/custom/{client:word}[/]', name: 'system.guids.custom.client')]
    public function custom_client(string $client): iResponse
    {
        if (false === array_key_exists($client, Config::get('supported', []))) {
            return api_error('Client name is unsupported or incorrect.', Status::NOT_FOUND);
        }

        return api_response(
            Status::OK,
            array_filter(ag($this->getData(), 'links', []), fn($link) => $link['type'] === $client)
        );
    }

    #[Put(self::URL . '/custom/{client:word}[/]', name: 'system.guids.custom.client.link.add')]
    public function custom_client_link_add(iRequest $request, string $client): iResponse
    {
        $params = DataUtil::fromRequest($request);

        $requiredFields = [
            'type',
            'map.from',
            'map.to',
        ];

        if ('plex' === $client) {
            $requiredFields[] = 'options.legacy';
        }

        foreach ($requiredFields as $field) {
            if (!$params->get($field)) {
                return api_error(r("Field '{field}' is required. And is missing from request.", [
                    'field' => $field
                ]), Status::BAD_REQUEST);
            }
        }

        if (false === array_key_exists($client, Config::get('supported', []))) {
            return api_error(r("Client name '{client}' is unsupported or incorrect.", [
                'client' => $client
            ]), Status::BAD_REQUEST);
        }

        $mapTo = $params->get('map.to');
        if (false === str_starts_with($mapTo, 'guid_')) {
            $mapTo = 'guid_' . $mapTo;
        }

        if (false === array_key_exists($mapTo, Guid::getSupported())) {
            return api_error(r("The map.to GUID '{guid}' is not supported.", [
                'guid' => $params->get('map.to')
            ]), Status::BAD_REQUEST);
        }

        $exists = array_any(
            ag($this->getData(), 'links', []),
            fn($link) => $link['type'] === $client && $link['map']['from'] === $params->get('map.from')
        );

        if (true === $exists) {
            return api_error(
                r("The client '{client}' map.from '{from}' is already exists.", [
                    'client' => $client,
                    'from' => $params->get('map.from')
                ]),
                Status::BAD_REQUEST
            );
        }

        $link = [
            'id' => generateUUID(),
            'type' => $client,
            'map' => [
                'from' => $params->get('map.from'),
                'to' => $mapTo,
            ],
        ];

        if ('plex' === $client) {
            $link['options'] = [
                'legacy' => (bool)$params->get('options.legacy'),
            ];

            if ($params->get('replace.from') && $params->get('replace.to')) {
                $link['replace'] = [
                    'from' => $params->get('replace.from'),
                    'to' => $params->get('replace.to'),
                ];
            }
        }

        $file = ConfigFile::open(Config::get('guid.file'), 'yaml', autoSave: false, autoCreate: true, autoBackup: true);

        if (false === $file->has('links') || false === is_array($file->get('links'))) {
            $file->set('links', []);
        }

        $links = array_values($file->get('links', []));
        $links[] = $link;

        $file->set('links', null)->set('links', $links)->persist();

        return api_response(Status::OK, $link);
    }

    #[Delete(self::URL . '/custom/{client:word}/{id:uuid}[/]', name: 'system.guids.custom.client.remove')]
    public function custom_client_guid_remove(string $client, string $id): iResponse
    {
        $file = ConfigFile::open(Config::get('guid.file'), 'yaml', autoCreate: true, autoBackup: true);
        $refIndex = $refData = null;

        foreach ($file->get('links', []) as $i => $link) {
            if (ag($link, 'type') !== $client || ag($link, 'id') !== $id) {
                continue;
            }

            $refIndex = $i;
            $refData = $link;
            break;
        }

        if (null === $refIndex) {
            return api_error(r("The client '{client}' link id '{id}' is not found.", [
                'client' => $client,
                'id' => $id
            ]), Status::NOT_FOUND);
        }

        $file->delete('links.' . $refIndex);

        $links = $file->get('links', []);
        $file->set('links', null)
            ->set('links', array_values($links))
            ->persist();

        return api_response(Status::OK, $refData);
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

        if (false === file_exists($file)) {
            return [
                'version' => Config::get('guid.version'),
                'guids' => [],
                'links' => [],
            ];
        }

        $data = ConfigFile::open($file, 'yaml');

        return [
            'version' => $data->get('version', Config::get('guid.version')),
            'guids' => $data->get('guids', []),
            'links' => $data->get('links', []),
        ];
    }

    private function validateName(string $name): void
    {
        $guidName = after($name, 'guid_');

        if (false === preg_match('/^[a-z0-9_]+$/i', $guidName)) {
            throw new InvalidArgumentException('Name must be alphanumeric and underscores only.');
        }

        if (strtolower($guidName) !== $guidName) {
            throw new InvalidArgumentException('Name must be lowercase.');
        }

        if (str_contains($guidName, ' ')) {
            throw new InvalidArgumentException('Name must not contain spaces.');
        }

        if (true === array_key_exists($name, Guid::getSupported())) {
            throw new InvalidArgumentException(r("GUID name '{name}' is already in use.", ['name' => $name]));
        }
    }
}
