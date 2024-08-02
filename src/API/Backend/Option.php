<?php

declare(strict_types=1);

namespace App\API\Backend;

use App\Libs\Attributes\Route\Route;
use App\Libs\Config;
use App\Libs\ConfigFile;
use App\Libs\DataUtil;
use App\Libs\Enums\Http\Status;
use App\Libs\Exceptions\ValidationException;
use App\Libs\Traits\APITraits;
use Psr\Http\Message\ResponseInterface as iResponse;
use Psr\Http\Message\ServerRequestInterface as iRequest;

final class Option
{
    use APITraits;

    #[Route(['GET', 'POST', 'PATCH', 'DELETE'], Index::URL . '/{name:backend}/option[/{option}[/]]')]
    public function __invoke(iRequest $request, array $args = []): iResponse
    {
        if (null === ($name = ag($args, 'name'))) {
            return api_error('Invalid value for name path parameter.', Status::HTTP_BAD_REQUEST);
        }

        $list = ConfigFile::open(Config::get('backends_file'), 'yaml', autoCreate: true);

        if (false === $list->has($name)) {
            return api_error(r("Backend '{name}' not found.", ['name' => $name]), Status::HTTP_NOT_FOUND);
        }

        $data = DataUtil::fromRequest($request);

        if (null === ($option = ag($args, 'option', $data->get('key')))) {
            return api_error('No option key was given.', Status::HTTP_BAD_REQUEST);
        }

        $isInternalRequest = true === (bool)$request->getAttribute('INTERNAL_REQUEST', false);

        if (false === str_starts_with($option, 'options.') && !$isInternalRequest) {
            return api_error(
                "Invalid option key was given. Option keys must start with 'options.'",
                Status::HTTP_BAD_REQUEST
            );
        }

        $spec = getServerColumnSpec($option);
        if (empty($spec)) {
            return api_error(r("Invalid option '{key}'.", ['key' => $option]), Status::HTTP_BAD_REQUEST);
        }

        if ('GET' === $request->getMethod()) {
            if (false === $list->has($name . '.' . $option)) {
                return api_error(r("Option '{option}' not found in backend '{name}' config.", [
                    'option' => $option,
                    'name' => $name
                ]), Status::HTTP_NOT_FOUND);
            }

            return $this->viewOption($spec, $list->get("{$name}.{$option}"));
        }

        if ('DELETE' === $request->getMethod() && false === $list->has("{$name}.{$option}")) {
            return api_error(r("Option '{option}' not found in backend '{name}' config.", [
                'option' => $option,
                'name' => $name
            ]), Status::HTTP_NOT_FOUND);
        }

        if ('DELETE' === $request->getMethod()) {
            if (null !== ($value = $list->get($name . '.' . $option))) {
                settype($value, ag($spec, 'type', 'string'));
            }
            $list->delete("{$name}.{$option}");
        } else {
            if (null !== ($value = $data->get('value'))) {
                if (ag($spec, 'type', 'string') === 'bool') {
                    $value = $this->castToBool($value);
                } else {
                    settype($value, ag($spec, 'type', 'string'));
                }
            }

            if (ag_exists($spec, 'validate')) {
                try {
                    $value = $spec['validate']($value, $spec);
                } catch (ValidationException $e) {
                    return api_error(r("Value validation for '{key}' failed. {error}", [
                        'key' => $option,
                        'error' => $e->getMessage()
                    ]), Status::HTTP_BAD_REQUEST);
                }
            }

            $list->set("{$name}.{$option}", $value);
        }

        $list->persist();

        return api_response(Status::HTTP_OK, [
            'key' => $option,
            'value' => $value,
            'real_val' => $data->get('value'),
            'type' => ag($spec, 'type', 'string'),
            'description' => ag($spec, 'description', ''),
        ]);
    }

    public function viewOption(array $spec, mixed $value): iResponse
    {
        if (null !== $value) {
            settype($value, ag($spec, 'type', 'string'));
        }

        return api_response(Status::HTTP_OK, [
            'key' => $spec['key'],
            'value' => $value,
            'type' => ag($spec, 'type', 'string'),
            'description' => ag($spec, 'description', ''),
        ]);
    }

    private function castToBool(mixed $value): bool
    {
        if (is_bool($value)) {
            return $value;
        }

        if (is_string($value)) {
            $value = strtolower($value);
            if ('true' === $value || 'on' === $value || 'yes' === $value) {
                return true;
            }
            if ('false' === $value || 'off' === $value || 'no' === $value) {
                return false;
            }
        }

        return (bool)$value;
    }
}
