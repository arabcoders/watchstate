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

        if (false === str_starts_with($option, 'options.')) {
            return api_error(
                "Invalid option. Option path parameter keys must start with 'options.'",
                HTTP_STATUS::HTTP_BAD_REQUEST
            );
        }

        $spec = getServerColumnSpec($option);
        if (empty($spec)) {
            return api_error(r("Invalid option '{key}'.", ['key' => $option]), HTTP_STATUS::HTTP_BAD_REQUEST);
        }

        $list = ConfigFile::open(Config::get('backends_file'), 'yaml', autoCreate: true);

        if (false === $list->has($name)) {
            return api_error(r("Backend '{name}' not found.", ['name' => $name]), HTTP_STATUS::HTTP_NOT_FOUND);
        }

        if (false === $list->has($name . '.' . $option)) {
            return api_error(r("Option '{option}' not found in backend '{name}' config.", [
                'option' => $option,
                'name' => $name
            ]), HTTP_STATUS::HTTP_NOT_FOUND);
        }

        $value = $list->get($name . '.' . $option);
        settype($value, ag($spec, 'type', 'string'));

        return api_response(HTTP_STATUS::HTTP_OK, [
            'key' => $spec['key'],
            'value' => $value,
            'type' => ag($spec, 'type', 'string'),
            'description' => ag($spec, 'description', ''),
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
            return api_error('No option key was given.', HTTP_STATUS::HTTP_BAD_REQUEST);
        }

        if (false === str_starts_with($option, 'options.')) {
            return api_error(
                "Invalid option key was given. Option keys must start with 'options.'",
                HTTP_STATUS::HTTP_BAD_REQUEST
            );
        }

        $spec = getServerColumnSpec($option);
        if (empty($spec)) {
            return api_error(r("Invalid option '{key}'.", ['key' => $option]), HTTP_STATUS::HTTP_BAD_REQUEST);
        }

        if ($list->has($name . '.' . $option)) {
            return api_error(r("Option '{option}' already exists in backend '{name}'.", [
                'option' => $option,
                'name' => $name
            ]), HTTP_STATUS::HTTP_CONFLICT);
        }

        $value = $data->get('value');
        settype($value, ag($spec, 'type', 'string'));

        $list->set($name . '.' . $option, $value)->persist();

        return api_response(HTTP_STATUS::HTTP_OK, [
            'key' => $option,
            'value' => $value,
            'type' => ag($spec, 'type', 'string'),
            'description' => ag($spec, 'description', ''),
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

        if (false === str_starts_with($option, 'options.')) {
            return api_error(
                "Invalid option key was given. Option keys must start with 'options.'",
                HTTP_STATUS::HTTP_BAD_REQUEST
            );
        }

        $spec = getServerColumnSpec($option);
        if (empty($spec)) {
            return api_error(r("Invalid option '{key}'.", ['key' => $option]), HTTP_STATUS::HTTP_BAD_REQUEST);
        }

        $list = ConfigFile::open(Config::get('backends_file'), 'yaml', autoCreate: true);
        if (false === $list->has($name)) {
            return api_error(r("Backend '{name}' not found.", ['name' => $name]), HTTP_STATUS::HTTP_NOT_FOUND);
        }

        if (false === $list->has($name . '.' . $option)) {
            return api_error(r("Option '{option}' not found in backend '{name}' config.", [
                'option' => $option,
                'name' => $name
            ]), HTTP_STATUS::HTTP_NOT_FOUND);
        }

        if (true === (bool)ag($spec, 'immutable', false)) {
            return api_error(r("Option '{option}' is immutable.", [
                'option' => $option,
            ]), HTTP_STATUS::HTTP_CONFLICT);
        }

        $data = DataUtil::fromRequest($request);

        if (null === ($value = $data->get('value'))) {
            return api_error(r("No value was provided for '{key}'.", [
                'key' => $option,
            ]), HTTP_STATUS::HTTP_BAD_REQUEST);
        }

        settype($value, ag($spec, 'type', 'string'));

        $oldValue = $list->get($name . '.' . $option);
        if (null !== $oldValue) {
            settype($oldValue, ag($spec, 'type', 'string'));
        }

        if ($oldValue !== $value) {
            $list->set($name . '.' . $option, $value)->persist();
        }

        return api_response(HTTP_STATUS::HTTP_OK, [
            'key' => $option,
            'value' => $value,
            'type' => ag($spec, 'type', 'string'),
            'description' => ag($spec, 'description', ''),
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

        if (false === str_starts_with($option, 'options.')) {
            return api_error(
                "Invalid option key was given. Option keys must start with 'options.'",
                HTTP_STATUS::HTTP_BAD_REQUEST
            );
        }

        $spec = getServerColumnSpec($option);
        if (empty($spec)) {
            return api_error(r("Invalid option '{key}'.", ['key' => $option]), HTTP_STATUS::HTTP_BAD_REQUEST);
        }

        $list = ConfigFile::open(Config::get('backends_file'), 'yaml', autoCreate: true);
        if (false === $list->has($name)) {
            return api_error(r("Backend '{name}' not found.", ['name' => $name]), HTTP_STATUS::HTTP_NOT_FOUND);
        }

        if (false === $list->has($name . '.' . $option)) {
            return api_error(r("Option '{option}' not found in backend '{name}' config.", [
                'option' => $option,
                'name' => $name
            ]), HTTP_STATUS::HTTP_NOT_FOUND);
        }

        $value = $list->get($name . '.' . $option);
        settype($value, ag($spec, 'type', 'string'));

        $list->delete($option)->persist();

        return api_response(HTTP_STATUS::HTTP_OK, [
            'key' => $option,
            'value' => $value,
            'type' => ag($spec, 'type', 'string'),
            'description' => ag($spec, 'description', ''),
        ]);
    }
}
