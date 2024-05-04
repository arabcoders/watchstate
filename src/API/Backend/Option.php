<?php

declare(strict_types=1);

namespace App\API\Backend;

use App\Libs\Attributes\Route\Delete;
use App\Libs\Attributes\Route\Get;
use App\Libs\Attributes\Route\Patch;
use App\Libs\Attributes\Route\Post;
use App\Libs\Config;
use App\Libs\ConfigFile;
use App\Libs\DataUtil;
use App\Libs\HTTP_STATUS;
use App\Libs\Traits\APITraits;
use Psr\Http\Message\ResponseInterface as iResponse;
use Psr\Http\Message\ServerRequestInterface as iRequest;

final class Option
{
    use APITraits;

    #[Get(Index::URL . '/{name:backend}/option/{option}[/]', name: 'backend.option')]
    public function viewOption(iRequest $request, array $args = []): iResponse
    {
        if (null === ($name = ag($args, 'name'))) {
            return api_error('Invalid value for name path parameter.', HTTP_STATUS::HTTP_BAD_REQUEST);
        }

        if (null === ($option = ag($args, 'option'))) {
            return api_error('Invalid value for option path parameter.', HTTP_STATUS::HTTP_BAD_REQUEST);
        }

        $list = ConfigFile::open(Config::get('backends_file'), 'yaml', autoCreate: true);

        if (false === $list->has($name)) {
            return api_error(r("Backend '{name}' not found.", ['name' => $name]), HTTP_STATUS::HTTP_NOT_FOUND);
        }

        $key = $name . '.options.' . $option;

        if (false === $list->has($key)) {
            return api_error(r("Option '{option}' not found in backend '{name}'.", [
                'option' => $option,
                'name' => $name
            ]), HTTP_STATUS::HTTP_NOT_FOUND);
        }

        $value = $list->get($key);
        return api_response(HTTP_STATUS::HTTP_OK, [
            'key' => $option,
            'value' => $value,
            'type' => get_debug_type($value),
        ]);
    }

    #[Post(Index::URL . '/{name:backend}/option[/]', name: 'backend.option.add')]
    public function addOption(iRequest $request, array $args = []): iResponse
    {
        if (null === ($name = ag($args, 'name'))) {
            return api_error('Invalid value for name path parameter.', HTTP_STATUS::HTTP_BAD_REQUEST);
        }

        $list = ConfigFile::open(Config::get('backends_file'), 'yaml', autoCreate: true);

        if (false === $list->has($name)) {
            return api_error(r("Backend '{name}' not found.", ['name' => $name]), HTTP_STATUS::HTTP_NOT_FOUND);
        }

        $data = DataUtil::fromRequest($request);

        if (null === ($option = $data->get('key'))) {
            return api_error('Invalid value for key.', HTTP_STATUS::HTTP_BAD_REQUEST);
        }

        $spec = require __DIR__ . '/../../../config/backend.spec.php';
        $found = false;

        foreach ($spec as $supportedKey => $_) {
            if (str_ends_with($supportedKey, 'options.' . $option)) {
                $found = true;
                break;
            }
        }

        if (false === $found) {
            return api_error(r("Option '{key}' is not supported.", ['key' => $option]), HTTP_STATUS::HTTP_BAD_REQUEST);
        }

        $value = $data->get('value');

        $list->set($name . '.options.' . $option, $value)->persist();

        return api_response(HTTP_STATUS::HTTP_OK, [
            'key' => $option,
            'value' => $value,
            'type' => get_debug_type($value),
        ]);
    }

    #[Patch(Index::URL . '/{name:backend}/option/{option}[/]', name: 'backend.option.update')]
    public function updateOption(iRequest $request, array $args = []): iResponse
    {
        if (null === ($name = ag($args, 'name'))) {
            return api_error('Invalid value for name path parameter.', HTTP_STATUS::HTTP_BAD_REQUEST);
        }

        if (null === ($option = ag($args, 'option'))) {
            return api_error('Invalid value for option parameter.', HTTP_STATUS::HTTP_BAD_REQUEST);
        }

        $list = ConfigFile::open(Config::get('backends_file'), 'yaml', autoCreate: true);

        if (false === $list->has($name)) {
            return api_error(r("Backend '{name}' not found.", ['name' => $name]), HTTP_STATUS::HTTP_NOT_FOUND);
        }

        $key = $name . '.options.' . $option;
        if (false === $list->has($key)) {
            return api_error(r("Option '{option}' not found in backend '{name}'.", [
                'option' => $option,
                'name' => $name
            ]), HTTP_STATUS::HTTP_NOT_FOUND);
        }

        $data = DataUtil::fromRequest($request);

        if (null === ($value = $data->get('value'))) {
            return api_error(r("No value was provided for '{key}'.", [
                'key' => $key,
            ]), HTTP_STATUS::HTTP_BAD_REQUEST);
        }

        $list->set($key, $value)->persist();

        return api_response(HTTP_STATUS::HTTP_OK, [
            'key' => $option,
            'value' => $value,
            'type' => get_debug_type($value),
        ]);
    }

    #[Delete(Index::URL . '/{name:backend}/option/{option}[/]', name: 'backend.option.delete')]
    public function deleteOption(iRequest $request, array $args = []): iResponse
    {
        if (null === ($name = ag($args, 'name'))) {
            return api_error('Invalid value for name path parameter.', HTTP_STATUS::HTTP_BAD_REQUEST);
        }

        if (null === ($option = ag($args, 'option'))) {
            return api_error('Invalid value for option option parameter.', HTTP_STATUS::HTTP_BAD_REQUEST);
        }

        $list = ConfigFile::open(Config::get('backends_file'), 'yaml', autoCreate: true);

        if (false === $list->has($name)) {
            return api_error(r("Backend '{name}' not found.", ['name' => $name]), HTTP_STATUS::HTTP_NOT_FOUND);
        }

        $key = $name . '.options.' . $option;

        $value = $list->get($key);
        $list->delete($key)->persist();

        return api_response(HTTP_STATUS::HTTP_OK, [
            'key' => $option,
            'value' => $value,
            'type' => get_debug_type($value),
        ]);
    }
}
