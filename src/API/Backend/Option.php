<?php

declare(strict_types=1);

namespace App\API\Backend;

use App\Libs\Attributes\Route\Route;
use App\Libs\DataUtil;
use App\Libs\Enums\Http\Status;
use App\Libs\Exceptions\ValidationException;
use App\Libs\Mappers\ExtendedImportInterface as iEImport;
use App\Libs\Traits\APITraits;
use Psr\Http\Message\ResponseInterface as iResponse;
use Psr\Http\Message\ServerRequestInterface as iRequest;
use Psr\Log\LoggerInterface as iLogger;

final class Option
{
    use APITraits;

    public function __construct(private readonly iEImport $mapper, private readonly iLogger $logger)
    {
    }

    #[Route(['GET', 'POST', 'PATCH', 'DELETE'], Index::URL . '/{name:backend}/option[/{option}[/]]')]
    public function __invoke(iRequest $request, string $name, string|null $option = null): iResponse
    {
        $userContext = $this->getUserContext($request, $this->mapper, $this->logger);

        if (false === $userContext->config->has($name)) {
            return api_error(r("Backend '{name}' not found.", ['name' => $name]), Status::NOT_FOUND);
        }

        $data = DataUtil::fromRequest($request);

        if (null === ($option = $option ?? $data->get('key'))) {
            return api_error('No option key was given.', Status::BAD_REQUEST);
        }

        $isInternalRequest = true === (bool)$request->getAttribute('INTERNAL_REQUEST', false);

        if (false === str_starts_with($option, 'options.') && !$isInternalRequest) {
            return api_error(
                "Invalid option key was given. Option keys must start with 'options.'",
                Status::BAD_REQUEST
            );
        }

        $spec = getServerColumnSpec($option);
        if (empty($spec)) {
            return api_error(r("Invalid option '{key}'.", ['key' => $option]), Status::BAD_REQUEST);
        }

        if ('GET' === $request->getMethod()) {
            if (false === $userContext->config->has($name . '.' . $option)) {
                return api_error(r("Option '{option}' not found in backend '{name}' config.", [
                    'option' => $option,
                    'name' => $name
                ]), Status::NOT_FOUND);
            }

            return $this->viewOption($spec, $userContext->config->get("{$name}.{$option}"));
        }

        if ('DELETE' === $request->getMethod() && false === $userContext->config->has("{$name}.{$option}")) {
            return api_error(r("Option '{option}' not found in backend '{name}' config.", [
                'option' => $option,
                'name' => $name
            ]), Status::NOT_FOUND);
        }

        if ('DELETE' === $request->getMethod()) {
            if (null !== ($value = $userContext->config->get($name . '.' . $option))) {
                settype($value, ag($spec, 'type', 'string'));
            }
            $userContext->config->delete("{$name}.{$option}");
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
                    ]), Status::BAD_REQUEST);
                }
            }

            $userContext->config->set("{$name}.{$option}", $value);
        }

        $userContext->config->persist();

        return api_response(Status::OK, [
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

        return api_response(Status::OK, [
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
