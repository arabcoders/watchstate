<?php

declare(strict_types=1);

namespace App\API\System;

use App\Libs\Attributes\Route\Delete;
use App\Libs\Attributes\Route\Get;
use App\Libs\Attributes\Route\Post;
use App\Libs\Attributes\Route\Put;
use App\Libs\Config;
use App\Libs\ConfigFile;
use App\Libs\DataUtil;
use App\Libs\HTTP_STATUS;
use App\Libs\LogSuppressor;
use Psr\Http\Message\ResponseInterface as iResponse;
use Psr\Http\Message\ServerRequestInterface as iRequest;
use Random\RandomException;

final class Suppressor
{
    public const string URL = '%{api.prefix}/system/suppressor';

    private ConfigFile $file;

    public function __construct(private readonly LogSuppressor $suppressor)
    {
        $this->file = (new ConfigFile(file: Config::get('path') . '/config/suppress.yaml', autoCreate: true));
    }

    #[Get(self::URL . '[/]', name: 'system.suppressor')]
    public function __invoke(iRequest $request, array $args = []): iResponse
    {
        $list = [];

        foreach ($this->file->getAll() as $id => $data) {
            $list[] = ['id' => $id, ...$data,];
        }

        return api_response(HTTP_STATUS::HTTP_OK, $list);
    }

    /**
     * @throws RandomException
     */
    #[Post(self::URL . '[/]', name: 'system.suppressor.add')]
    public function addSuppressor(iRequest $request, array $args = []): iResponse
    {
        $params = DataUtil::fromRequest($request);

        if (null === ($rule = $params->get('rule')) || empty($rule)) {
            return api_error('Rule is required.', HTTP_STATUS::HTTP_BAD_REQUEST);
        }

        if (null === ($type = $params->get('type')) || empty($type)) {
            return api_error('Rule type is required.', HTTP_STATUS::HTTP_BAD_REQUEST);
        }

        $type = strtolower($type);

        if (null === ($example = $params->get('example')) || empty($example)) {
            return api_error('Rule example is required.', HTTP_STATUS::HTTP_BAD_REQUEST);
        }

        if ('regex' === $type) {
            if (false === @preg_match($rule, '')) {
                return api_error('Invalid regex pattern.', HTTP_STATUS::HTTP_BAD_REQUEST);
            }
            if (false === @preg_match($rule, $example)) {
                return api_error('Example does not match the regex pattern.', HTTP_STATUS::HTTP_BAD_REQUEST);
            }
        } else {
            if (false === str_contains($example, $rule)) {
                return api_error('Example does not contain the message text.', HTTP_STATUS::HTTP_BAD_REQUEST);
            }
        }

        if ($this->suppressor->isSuppressed($example)) {
            return api_error('Example is already suppressed by another rule.', HTTP_STATUS::HTTP_BAD_REQUEST);
        }

        $data = [
            'type' => $type,
            'rule' => $rule,
            'example' => $example,
        ];

        $id = ag($args, 'id', $this->createId());

        $this->file->set($id, $data)->persist();

        return api_response(HTTP_STATUS::HTTP_OK, ['id' => $id, ...$data]);
    }

    /**
     * @throws RandomException
     */
    #[Put(self::URL . '/{id:\w{11}}[/]', name: 'system.suppressor.edit')]
    public function editSuppressor(iRequest $request, array $args = []): iResponse
    {
        return $this->addSuppressor($request, $args);
    }

    #[Delete(self::URL . '/{id:\w{11}}[/]', name: 'system.suppressor.delete')]
    public function deleteSuppressor(iRequest $request, array $args = []): iResponse
    {
        if (null === ($id = ag($args, 'id')) || empty($id)) {
            return api_error('Invalid suppressor id.', HTTP_STATUS::HTTP_BAD_REQUEST);
        }

        if (null === ($rule = $this->file->get($id))) {
            return api_error('Suppressor rule not found.', HTTP_STATUS::HTTP_NOT_FOUND);
        }

        $this->file->delete($id)->persist();

        return api_response(HTTP_STATUS::HTTP_OK, ['id' => $id, ...$rule]);
    }

    #[Get(self::URL . '/{id:\w{11}}[/]', name: 'system.suppressor.view')]
    public function viewSuppressor(iRequest $request, array $args = []): iResponse
    {
        if (null === ($id = ag($args, 'id')) || empty($id)) {
            return api_error('Invalid suppressor id.', HTTP_STATUS::HTTP_BAD_REQUEST);
        }

        if (null === ($rule = $this->file->get($id))) {
            return api_error('Suppressor rule not found.', HTTP_STATUS::HTTP_NOT_FOUND);
        }

        return api_response(HTTP_STATUS::HTTP_OK, ['id' => $id, ...$rule]);
    }

    /**
     * @throws RandomException
     */
    private function createId(): string
    {
        return 'S' . bin2hex(random_bytes(5));
    }
}
