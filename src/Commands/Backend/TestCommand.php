<?php

declare(strict_types=1);

namespace App\Commands\Backend;

use App\Backends\Common\ClientInterface as iClient;
use App\Backends\Common\Context;
use App\Command;
use App\Libs\Attributes\Route\Cli;
use App\Libs\Enums\Http\Method;
use App\Libs\Mappers\ImportInterface as iImport;
use App\Libs\QueueRequests;
use App\Libs\Stream;
use App\Libs\Uri;
use BackedEnum;
use DateTimeInterface;
use Psr\Http\Message\StreamInterface as iStream;
use Psr\Http\Message\UriInterface as iUri;
use Psr\Log\LoggerInterface as iLogger;
use ReflectionClass;
use ReflectionIntersectionType;
use ReflectionMethod;
use ReflectionNamedType;
use ReflectionParameter;
use ReflectionType;
use ReflectionUnionType;
use Stringable;
use Symfony\Component\Console\Completion\CompletionInput;
use Symfony\Component\Console\Completion\CompletionSuggestions;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface as iInput;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface as iOutput;
use Throwable;
use UnitEnum;

#[Cli(command: self::ROUTE)]
class TestCommand extends Command
{
    public const string ROUTE = 'backend:test';

    private const array EXCLUDED_ACTIONS = [
        'withContext',
        'setLogger',
        'processRequest',
        'parseWebhook',
        'fromRequest',
    ];

    protected function configure(): void
    {
        $this
            ->setName(self::ROUTE)
            ->setDescription('Inspect or invoke backend client actions.')
            ->addArgument('action', InputArgument::OPTIONAL, 'ClientInterface method name to invoke.')
            ->addOption('select-backend', 's', InputOption::VALUE_REQUIRED, 'Select backend.')
            ->addOption('inspect', 'i', InputOption::VALUE_NONE, 'Inspect the selected action signature and parameter requirements.')
            ->addOption(
                'param',
                'p',
                InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY,
                'Action parameter in key=value format. Unknown keys are routed into opts when supported.',
            )
            ->setHelp(
                r(
                    <<<HELP
                        Inspect or invoke backend client actions using the method names.

                        Examples:

                        {cmd} <cmd>{route}</cmd> -s <value>plex_main</value>
                        {cmd} <cmd>{route}</cmd> -s <value>plex_main</value> -i <value>getUsersList</value>
                        {cmd} <cmd>{route}</cmd> -s <value>plex_main</value> <value>getUsersList</value> --param <value>GET_TOKENS=true</value>
                        {cmd} <cmd>{route}</cmd> -s <value>plex_main</value> <value>getUserToken</value> --param <value>userId=1234</value> --param <value>username=alice</value> --param <value>PLEX_EXTERNAL_USER=true</value>
                        {cmd} <cmd>{route}</cmd> -s <value>plex_main</value> <value>search</value> --param <value>query=batman</value> --param <value>limit=5</value> --param <value>include_guids=true</value>
                        HELP,
                    [
                        'cmd' => trim(command_context()),
                        'route' => self::ROUTE,
                    ],
                ),
            );
    }

    protected function runCommand(iInput $input, iOutput $output): int
    {
        $mode = strtolower((string) $input->getOption('output'));
        if (!in_array($mode, self::DISPLAY_OUTPUT, true)) {
            $mode = 'table';
        }

        $inspect = (bool) $input->getOption('inspect');

        try {
            $backend = $this->getSelectedBackend($input);
            $action = $input->getArgument('action');

            if (!is_string($action) || '' === trim($action)) {
                if (true === $inspect) {
                    throw new \InvalidArgumentException(
                        'Select an action to inspect. For example: backend:test -s backend_name -i getUsersList',
                    );
                }

                $this->displayActions($backend, $output, $mode);
                return self::SUCCESS;
            }

            $action = $this->resolveActionName($action);

            if (true === $inspect) {
                $inspection = $this->inspectAction($backend, $action);
                $this->displayInspection($inspection, $output, $mode);
                return self::SUCCESS;
            }

            $params = $this->parseParameters((array) $input->getOption('param'));
            $result = $this->invokeAction($backend, $action, $params);
            $normalized = $this->normalizeValue($result);
        } catch (Throwable $e) {
            $output->writeln(r('<error>{kind}: {message}</error>', [
                'kind' => $e::class,
                'message' => $e->getMessage(),
            ]));

            return self::FAILURE;
        }

        $payload = [
            'backend' => $backend->getName(),
            'type' => $backend->getType(),
            'action' => $action,
            'params' => $params,
            'result' => $normalized,
        ];

        if ('table' !== $mode) {
            $this->displayContent($payload, $output, $mode);
            return self::SUCCESS;
        }

        $this->displayResultTable($normalized, $output);

        return self::SUCCESS;
    }

    public function complete(CompletionInput $input, CompletionSuggestions $suggestions): void
    {
        parent::complete($input, $suggestions);

        if (!$input->mustSuggestArgumentValuesFor('action')) {
            return;
        }

        $currentValue = $input->getCompletionValue();
        $suggestions->suggestValues(array_values(array_filter(
            array_map(static fn(array $item) => $item['action'], $this->getAvailableActions()),
            static fn(string $action) => '' === $currentValue || str_starts_with($action, $currentValue),
        )));
    }

    /**
     * @return iClient
     */
    protected function getSelectedBackend(iInput $input): iClient
    {
        $backend = $input->getOption('select-backend');
        if (!is_string($backend) || '' === trim($backend)) {
            throw new \InvalidArgumentException('No backend selected. Use --select-backend to pick one backend.');
        }

        return $this->getBackend(trim($backend));
    }

    /**
     * @return array<int, array{action: string, signature: string, returns: string}>
     */
    protected function getAvailableActions(): array
    {
        $reflection = new ReflectionClass(iClient::class);
        $methods = array_filter(
            $reflection->getMethods(ReflectionMethod::IS_PUBLIC),
            static fn(ReflectionMethod $method) => false === in_array($method->getName(), self::EXCLUDED_ACTIONS, true),
        );

        usort($methods, static fn(ReflectionMethod $left, ReflectionMethod $right) => $left->getStartLine() <=> $right->getStartLine());

        return array_map(fn(ReflectionMethod $method) => [
            'action' => $method->getName(),
            'signature' => $this->formatMethodSignature($method),
            'returns' => $this->formatType($method->getReturnType()),
        ], $methods);
    }

    private function displayActions(iClient $backend, iOutput $output, string $mode): void
    {
        $payload = [
            'backend' => $backend->getName(),
            'type' => $backend->getType(),
            'actions' => $this->getAvailableActions(),
        ];

        if ('table' !== $mode) {
            $this->displayContent($payload, $output, $mode);
            return;
        }

        $this->displayContent($payload['actions'], $output, $mode);
    }

    /**
     * @return array<string, mixed>
     */
    private function inspectAction(iClient $backend, string $action): array
    {
        $method = new ReflectionMethod(iClient::class, $action);
        $parameters = [];
        $required = [];
        $extraParamsTarget = null;

        foreach ($method->getParameters() as $parameter) {
            [$implicit] = $this->resolveImplicitValue($parameter, $backend);

            $requiredFromUser = false;
            $mode = 'optional';
            $default = null;
            $notes = [];

            if (true === $implicit) {
                $mode = 'auto';
                $notes[] = $this->getImplicitParameterNote($parameter);
            } elseif (false === $parameter->isDefaultValueAvailable()) {
                $mode = 'required';
                $requiredFromUser = true;
                $required[] = $parameter->getName();
            } else {
                $default = $this->formatDefaultValue($parameter->getDefaultValue());
            }

            if (true === $this->acceptsExtraParams($parameter)) {
                $extraParamsTarget = $parameter->getName();
                $notes[] = 'Receives unmatched --param keys.';
            }

            $parameters[] = [
                'name' => $parameter->getName(),
                'type' => $this->formatType($parameter->getType()),
                'mode' => $mode,
                'required' => $requiredFromUser ? 'yes' : 'no',
                'default' => $default,
                'notes' => implode(' ', array_filter($notes)),
            ];
        }

        return [
            'backend' => $backend->getName(),
            'type' => $backend->getType(),
            'action' => $action,
            'signature' => $this->formatMethodSignature($method),
            'returns' => $this->formatType($method->getReturnType()),
            'required_params' => $required,
            'accepts_extra_params' => null !== $extraParamsTarget,
            'extra_params_target' => $extraParamsTarget,
            'parameters' => $parameters,
        ];
    }

    /**
     * @param array<string, mixed> $inspection
     */
    private function displayInspection(array $inspection, iOutput $output, string $mode): void
    {
        if ('table' !== $mode) {
            $this->displayContent($inspection, $output, $mode);
            return;
        }

        $output->writeln(r('<info>Backend:</info> <comment>{backend}</comment> ({type})', [
            'backend' => $inspection['backend'],
            'type' => $inspection['type'],
        ]));
        $output->writeln(r('<info>Action:</info> <comment>{action}</comment>', ['action' => $inspection['action']]));
        $output->writeln(r('<info>Signature:</info> <comment>{signature}</comment>', ['signature' => $inspection['signature']]));
        $output->writeln(r('<info>Returns:</info> <comment>{returns}</comment>', ['returns' => $inspection['returns']]));
        $output->writeln(r('<info>Required user params:</info> <comment>{params}</comment>', [
            'params' => [] === $inspection['required_params'] ? 'None' : implode(', ', $inspection['required_params']),
        ]));
        $output->writeln(r('<info>Extra params target:</info> <comment>{target}</comment>', [
            'target' => $inspection['extra_params_target'] ?? 'None',
        ]));
        $output->writeln('');

        if ([] === $inspection['parameters']) {
            $output->writeln('<comment>No parameters.</comment>');
            return;
        }

        $this->displayContent($inspection['parameters'], $output, 'table');
    }

    private function resolveActionName(string $action): string
    {
        $needle = $this->normalizeActionToken($action);

        foreach ($this->getAvailableActions() as $item) {
            // @mago-expect lint:no-insecure-comparison
            if ($needle === $this->normalizeActionToken($item['action'])) {
                return $item['action'];
            }
        }

        throw new \InvalidArgumentException(r("Unknown backend action '{action}'.", ['action' => $action]));
    }

    private function getImplicitParameterNote(ReflectionParameter $parameter): string
    {
        foreach ($this->flattenTypes($parameter->getType()) as $type) {
            if ($type->isBuiltin()) {
                continue;
            }

            $typeName = $type->getName();

            if (Context::class === $typeName) {
                return 'Auto-supplied from the selected backend context.';
            }

            if (true === is_a($typeName, iImport::class, true)) {
                return 'Auto-supplied from the current user mapper.';
            }

            if (true === is_a($typeName, QueueRequests::class, true)) {
                return 'Auto-supplied as a fresh request queue.';
            }

            if (true === is_a($typeName, iLogger::class, true)) {
                return 'Auto-supplied from the backend logger.';
            }
        }

        return 'Auto-supplied by the command.';
    }

    private function acceptsExtraParams(ReflectionParameter $parameter): bool
    {
        if ('opts' !== $parameter->getName()) {
            return false;
        }

        foreach ($this->flattenTypes($parameter->getType()) as $type) {
            if ($type->isBuiltin() && 'array' === $type->getName()) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param array<int, string> $items
     *
     * @return array<string, mixed>
     */
    private function parseParameters(array $items): array
    {
        $params = [];

        foreach ($items as $item) {
            $pair = explode('=', $item, 2);
            if (2 !== count($pair)) {
                throw new \InvalidArgumentException(r("Invalid key=value input '{item}'.", ['item' => $item]));
            }

            [$key, $value] = $pair;
            $key = trim($key);

            if ('' === $key) {
                throw new \InvalidArgumentException('Parameter key cannot be empty.');
            }

            $this->setNestedValue($params, explode('.', $key), $this->parseScalarValue($value));
        }

        return $params;
    }

    /**
     * @param array<string, mixed> $params
     */
    private function invokeAction(iClient $backend, string $action, array $params): mixed
    {
        $method = new ReflectionMethod(iClient::class, $action);
        $arguments = $this->buildArguments($method, $backend, $params);

        return $backend->{$action}(...$arguments);
    }

    /**
     * @param array<string, mixed> $params
     *
     * @return array<int, mixed>
     */
    private function buildArguments(ReflectionMethod $method, iClient $backend, array $params): array
    {
        $resolved = [];
        $matched = [];

        foreach ($method->getParameters() as $parameter) {
            $name = $parameter->getName();

            if (array_key_exists($name, $params)) {
                $resolved[$name] = $this->coerceParameterValue($parameter, $params[$name]);
                $matched[$name] = true;
                continue;
            }

            [$implicit, $value] = $this->resolveImplicitValue($parameter, $backend);
            if (true === $implicit) {
                $resolved[$name] = $value;
                continue;
            }

            if ($parameter->isDefaultValueAvailable()) {
                $resolved[$name] = $parameter->getDefaultValue();
                continue;
            }

            throw new \InvalidArgumentException(r("Missing required parameter '{param}' for action '{action}'.", [
                'param' => $name,
                'action' => $method->getName(),
            ]));
        }

        $extra = array_diff_key($params, $matched);
        if ([] !== $extra) {
            if (!array_key_exists('opts', $resolved)) {
                throw new \InvalidArgumentException(r('Unknown parameters [{params}] for action `{action}`.', [
                    'params' => implode(', ', array_keys($extra)),
                    'action' => $method->getName(),
                ]));
            }

            if (!is_array($resolved['opts'])) {
                throw new \InvalidArgumentException('The opts parameter must be an array when extra parameters are routed into it.');
            }

            $resolved['opts'] = array_replace_recursive($extra, $resolved['opts']);
        }

        $arguments = [];
        foreach ($method->getParameters() as $parameter) {
            $arguments[] = $resolved[$parameter->getName()];
        }

        return $arguments;
    }

    /**
     * @return array{0: bool, 1: mixed}
     */
    private function resolveImplicitValue(ReflectionParameter $parameter, iClient $backend): array
    {
        foreach ($this->flattenTypes($parameter->getType()) as $type) {
            if ($type->isBuiltin()) {
                continue;
            }

            $typeName = $type->getName();

            if (Context::class === $typeName) {
                return [true, $backend->getContext()];
            }

            if (true === is_a($typeName, iImport::class, true)) {
                return [true, $backend->getContext()->userContext->mapper];
            }

            if (true === is_a($typeName, QueueRequests::class, true)) {
                return [true, new QueueRequests()];
            }

            if (true === is_a($typeName, iLogger::class, true)) {
                return [true, $backend->getContext()->logger];
            }
        }

        return [false, null];
    }

    private function coerceParameterValue(ReflectionParameter $parameter, mixed $value): mixed
    {
        $type = $parameter->getType();
        if (null === $type) {
            return $value;
        }

        if (null === $value && $parameter->allowsNull()) {
            return null;
        }

        foreach ($this->flattenTypes($type) as $namedType) {
            [$supported, $coerced] = $this->tryCoerceNamedType($namedType, $parameter->getName(), $value);
            if (true === $supported) {
                return $coerced;
            }
        }

        throw new \InvalidArgumentException(r("Unable to coerce parameter '{param}' to [{types}].", [
            'param' => $parameter->getName(),
            'types' => $this->formatType($type),
        ]));
    }

    /**
     * @return array{0: bool, 1: mixed}
     */
    private function tryCoerceNamedType(ReflectionNamedType $type, string $name, mixed $value): array
    {
        $typeName = $type->getName();

        if ('mixed' === $typeName) {
            return [true, $value];
        }

        if ($type->isBuiltin()) {
            return match ($typeName) {
                'string' => [true, $this->coerceString($value)],
                'int' => $this->coerceInt($value),
                'float' => $this->coerceFloat($value),
                'bool' => $this->coerceBool($value),
                'array' => is_array($value) ? [true, $value] : [false, null],
                default => [false, null],
            };
        }

        if (true === enum_exists($typeName)) {
            if ($value instanceof $typeName) {
                return [true, $value];
            }

            if (true === is_a($typeName, BackedEnum::class, true) && (is_string($value) || is_int($value))) {
                try {
                    return [true, $typeName::from($value)];
                } catch (Throwable) {
                    return [false, null];
                }
            }

            return [false, null];
        }

        if (true === is_a($typeName, Method::class, true)) {
            if ($value instanceof Method) {
                return [true, $value];
            }

            if (!is_string($value)) {
                return [false, null];
            }

            try {
                return [true, Method::from(strtoupper($value))];
            } catch (Throwable) {
                return [false, null];
            }
        }

        if (true === is_a($typeName, iUri::class, true)) {
            if ($value instanceof iUri) {
                return [true, $value];
            }

            return is_scalar($value) ? [true, new Uri((string) $value)] : [false, null];
        }

        if (true === is_a($typeName, iStream::class, true)) {
            if ($value instanceof iStream) {
                return [true, $value];
            }

            if (!is_scalar($value)) {
                return [false, null];
            }

            return (
                'writer' === $name
                    ? [true, Stream::make((string) $value, 'w+')]
                    : [true, Stream::create((string) $value)]
            );
        }

        if (true === is_a($typeName, DateTimeInterface::class, true)) {
            if ($value instanceof DateTimeInterface) {
                return [true, $value];
            }

            return is_scalar($value) ? [true, make_date((string) $value)] : [false, null];
        }

        return $value instanceof $typeName ? [true, $value] : [false, null];
    }

    private function coerceString(mixed $value): string
    {
        if (is_string($value)) {
            return $value;
        }

        if (is_scalar($value) || $value instanceof Stringable || $value instanceof UnitEnum || $value instanceof DateTimeInterface) {
            return (string) $value;
        }

        throw new \InvalidArgumentException('Expected a scalar value for string parameter coercion.');
    }

    /**
     * @return array{0: bool, 1: mixed}
     */
    private function coerceInt(mixed $value): array
    {
        if (is_int($value)) {
            return [true, $value];
        }

        if (is_string($value) && 1 === preg_match('/^-?\d+$/', $value)) {
            return [true, (int) $value];
        }

        return [false, null];
    }

    /**
     * @return array{0: bool, 1: mixed}
     */
    private function coerceFloat(mixed $value): array
    {
        if (is_float($value) || is_int($value)) {
            return [true, (float) $value];
        }

        if (is_string($value) && is_numeric($value)) {
            return [true, (float) $value];
        }

        return [false, null];
    }

    /**
     * @return array{0: bool, 1: mixed}
     */
    private function coerceBool(mixed $value): array
    {
        if (is_bool($value)) {
            return [true, $value];
        }

        if (!is_string($value)) {
            return [false, null];
        }

        return match (strtolower($value)) {
            '1', 'true', 'yes', 'on' => [true, true],
            '0', 'false', 'no', 'off' => [true, false],
            default => [false, null],
        };
    }

    private function parseScalarValue(string $value): mixed
    {
        $value = trim($value);

        if ('' === $value) {
            return '';
        }

        return match (strtolower($value)) {
            'true' => true,
            'false' => false,
            'null' => null,
            default => $this->parseStructuredScalarValue($value),
        };
    }

    private function parseStructuredScalarValue(string $value): mixed
    {
        if (1 === preg_match('/^-?\d+$/', $value)) {
            return (int) $value;
        }

        if (is_numeric($value)) {
            return (float) $value;
        }

        if (str_starts_with($value, '{') || str_starts_with($value, '[')) {
            try {
                return json_decode($value, true, flags: JSON_THROW_ON_ERROR | JSON_INVALID_UTF8_IGNORE);
            } catch (Throwable) {
                return $value;
            }
        }

        return $value;
    }

    /**
     * @param array<string, mixed> $target
     * @param array<int, string> $segments
     */
    private function setNestedValue(array &$target, array $segments, mixed $value): void
    {
        $key = array_shift($segments);
        if (null === $key || '' === $key) {
            throw new \InvalidArgumentException('Parameter key cannot be empty.');
        }

        if ([] === $segments) {
            $target[$key] = $value;
            return;
        }

        if (!isset($target[$key]) || !is_array($target[$key])) {
            $target[$key] = [];
        }

        $this->setNestedValue($target[$key], $segments, $value);
    }

    private function normalizeActionToken(string $value): string
    {
        return strtolower((string) preg_replace('/[^a-z0-9]+/i', '', $value));
    }

    private function formatMethodSignature(ReflectionMethod $method): string
    {
        $parts = [];

        foreach ($method->getParameters() as $parameter) {
            $part = '$' . $parameter->getName();
            $type = $this->formatType($parameter->getType());

            if ('' !== $type) {
                $part = $type . ' ' . $part;
            }

            if ($parameter->isDefaultValueAvailable()) {
                $part .= ' = ' . $this->formatDefaultValue($parameter->getDefaultValue());
            }

            $parts[] = $part;
        }

        return sprintf('%s(%s)', $method->getName(), implode(', ', $parts));
    }

    private function formatType(?ReflectionType $type): string
    {
        if (null === $type) {
            return '';
        }

        if ($type instanceof ReflectionUnionType) {
            return implode('|', array_map(static fn(ReflectionNamedType $named) => $named->getName(), $type->getTypes()));
        }

        if ($type instanceof ReflectionIntersectionType) {
            return implode('&', array_map(static fn(ReflectionNamedType $named) => $named->getName(), $type->getTypes()));
        }

        if ($type instanceof ReflectionNamedType) {
            return $type->getName();
        }

        return '';
    }

    private function formatDefaultValue(mixed $value): string
    {
        return match (true) {
            is_string($value) => "'{$value}'",
            is_bool($value) => $value ? 'true' : 'false',
            null === $value => 'null',
            is_array($value) && [] === $value => '[]',
            is_array($value) => json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_IGNORE),
            default => (string) $value,
        };
    }

    /**
     * @return array<int, ReflectionNamedType>
     */
    private function flattenTypes(?ReflectionType $type): array
    {
        if ($type instanceof ReflectionNamedType) {
            return [$type];
        }

        if ($type instanceof ReflectionUnionType) {
            return $type->getTypes();
        }

        if ($type instanceof ReflectionIntersectionType) {
            return $type->getTypes();
        }

        return [];
    }

    private function normalizeValue(mixed $value): mixed
    {
        if (null === $value || is_scalar($value)) {
            return $value;
        }

        if ($value instanceof BackedEnum) {
            return $value->value;
        }

        if ($value instanceof UnitEnum) {
            return $value->name;
        }

        if ($value instanceof DateTimeInterface) {
            return $value->format(DateTimeInterface::ATOM);
        }

        if ($value instanceof iUri || $value instanceof iStream || $value instanceof Stringable) {
            return (string) $value;
        }

        if ($value instanceof QueueRequests) {
            return $this->normalizeValue($value->getQueue());
        }

        if (is_array($value)) {
            $normalized = [];
            foreach ($value as $key => $item) {
                $normalized[$key] = $this->normalizeValue($item);
            }
            return $normalized;
        }

        if ($value instanceof \JsonSerializable) {
            return $this->normalizeValue($value->jsonSerialize());
        }

        if (is_object($value) && method_exists($value, 'toRequest')) {
            try {
                return $this->normalizeValue($value->toRequest());
            } catch (Throwable) {
                // Ignore and keep falling back.
            }
        }

        if (is_object($value)) {
            $vars = get_object_vars($value);
            if ([] !== $vars) {
                return $this->normalizeValue($vars);
            }
        }

        $encoded = json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_IGNORE);
        if (false !== $encoded) {
            $decoded = json_decode($encoded, true);
            if (JSON_ERROR_NONE === json_last_error()) {
                return $decoded;
            }
        }

        return ['class' => $value::class];
    }

    private function displayResultTable(mixed $result, iOutput $output): void
    {
        $rows = $this->normalizeTableRows($result);

        if ([] === $rows) {
            $output->writeln('<comment>No result returned.</comment>');
            return;
        }

        $this->displayContent($rows, $output, 'table');
    }

    /**
     * @return array<int, array<string, scalar|null>>
     */
    private function normalizeTableRows(mixed $result): array
    {
        if (!is_array($result)) {
            return [['result' => $this->normalizeTableCell($result)]];
        }

        if ([] === $result) {
            return [];
        }

        if (!array_is_list($result)) {
            return [$this->flattenTableRow($result)];
        }

        $rows = [];
        foreach ($result as $item) {
            if (is_array($item)) {
                $rows[] = $this->flattenTableRow($item);
                continue;
            }

            $rows[] = ['result' => $this->normalizeTableCell($item)];
        }

        return $rows;
    }

    /**
     * @param array<string, mixed> $row
     *
     * @return array<string, scalar|null>
     */
    private function flattenTableRow(array $row): array
    {
        $flat = [];

        foreach ($row as $key => $value) {
            $flat[(string) $key] = $this->normalizeTableCell($value);
        }

        return $flat;
    }

    private function normalizeTableCell(mixed $value): string|int|float|bool|null
    {
        $value = $this->normalizeValue($value);

        if (null === $value || is_scalar($value)) {
            return $value;
        }

        return json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_IGNORE);
    }
}
