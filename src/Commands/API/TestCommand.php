<?php

declare(strict_types=1);

namespace App\Commands\API;

use App\Command;
use App\Libs\APIResponse;
use App\Libs\Attributes\Route\Cli;
use App\Libs\Config;
use App\Libs\Enums\Http\Method;
use App\Libs\Enums\Http\Status;
use App\Libs\Response;
use InvalidArgumentException;
use JsonException;
use Symfony\Component\Console\Formatter\OutputFormatter;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface as iInput;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface as iOutput;
use Throwable;

#[Cli(command: self::ROUTE)]
final class TestCommand extends Command
{
    public const string ROUTE = 'api:test';

    protected function configure(): void
    {
        $this
            ->setName(self::ROUTE)
            ->setDescription('Test an internal API endpoint.')
            ->addArgument('path', InputArgument::REQUIRED, 'API path, for example /auth/test')
            ->addOption('method', 'm', InputOption::VALUE_REQUIRED, 'HTTP method to use.', Method::GET->value)
            ->addOption('body', 'b', InputOption::VALUE_REQUIRED, 'JSON body payload string.')
            ->addOption(
                'query',
                null,
                InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY,
                'Query string item in key=value format. Can be used multiple times.',
            )
            ->addOption(
                'header',
                'H',
                InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY,
                'Header in Name: value format. Can be used multiple times.',
            )
            ->addOption('user', 'u', InputOption::VALUE_REQUIRED, 'Optional X-User header override.')
            ->addOption('pretty', 'P', InputOption::VALUE_NONE, 'Pretty-print JSON bodies in the HTTP view.')
            ->setHelp(
                r(
                    <<<HELP
                            Test API route.

                            Examples:

                            {cmd} <cmd>{route}</cmd> <value>/system/healthcheck</value>
                            {cmd} <cmd>{route}</cmd> <value>/auth/login</value> -m <value>POST</value> -b <value>'{"username":"admin","password":"secret"}'</value>
                            {cmd} <cmd>{route}</cmd> <value>/auth/login</value> -m <value>POST</value> -b <value>'username=admin&password=secret'</value> -P
                            {cmd} <cmd>{route}</cmd> <value>/backends</value> -u <value>main</value> -H <value>'Authorization: Token abc'</value>
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
        try {
            $method = strtoupper((string) $input->getOption('method'));
            $path = $this->normalizePath((string) $input->getArgument('path'));
            $bodyInput = (string) ($input->getOption('body') ?? '');
            $body = $this->parseBody($bodyInput);
            $query = $this->parseKeyValueList((array) $input->getOption('query'));
            $headers = $this->parseHeaders((array) $input->getOption('header'));
            $pretty = (bool) $input->getOption('pretty');
        } catch (\InvalidArgumentException $e) {
            $output->writeln(r('<error>{message}</error>', [
                'message' => $e->getMessage(),
            ]));

            return self::FAILURE;
        }

        if (null !== ($user = $input->getOption('user'))) {
            $headers['X-User'] = (string) $user;
        }

        try {
            $response = api_request($method, $path, $body, [
                'headers' => $headers,
                'query' => $query,
            ]);
        } catch (Throwable $e) {
            $output->writeln(r('<error>API request failed. {message}</error>', [
                'message' => $e->getMessage(),
            ]));

            return self::FAILURE;
        }

        $requestPayload = $this->buildRequestPayload($method, $path, $headers, $query, $body);
        $responsePayload = $this->buildResponsePayload($response);

        if (!(bool) $input->getOption('json')) {
            $output->writeln($this->formatHttpRequest($requestPayload, $pretty));
            $output->writeln('');
            $output->writeln($this->formatHttpResponse($responsePayload, $pretty));

            return $response->status->value >= 400 ? self::FAILURE : self::SUCCESS;
        }

        $payload = [
            'request' => $requestPayload,
            'response' => $responsePayload,
        ];

        $this->displayContent($payload, $output, true);

        return $response->status->value >= 400 ? self::FAILURE : self::SUCCESS;
    }

    private function normalizePath(string $path): string
    {
        $path = trim($path);
        if ('' === $path) {
            throw new \InvalidArgumentException('Path is required.');
        }

        $prefix = rtrim((string) Config::get('api.prefix', ''), '/');
        if ('' !== $prefix && true === str_starts_with($path, $prefix)) {
            $path = substr($path, strlen($prefix));
        }

        if (false === str_starts_with($path, '/')) {
            $path = '/' . $path;
        }

        return $path;
    }

    /**
     * @return array<string,mixed>
     */
    private function parseBody(string $body): array
    {
        if ('' === ($body = trim($body))) {
            return [];
        }

        try {
            $decoded = json_decode($body, true, flags: JSON_THROW_ON_ERROR);
        } catch (JsonException $e) {
            parse_str($body, $decoded);
            if (count($decoded) > 0) {
                return $decoded;
            }
            throw new InvalidArgumentException('Body must be valid JSON.', previous: $e);
        }

        if (!is_array($decoded)) {
            throw new InvalidArgumentException('Body JSON must decode to an object or array.');
        }

        return $decoded;
    }

    /**
     * @param array<int,string> $items
     *
     * @return array<string,string>
     */
    private function parseKeyValueList(array $items): array
    {
        $result = [];

        foreach ($items as $item) {
            $pair = explode('=', $item, 2);
            if (2 !== count($pair)) {
                throw new \InvalidArgumentException(r("Invalid key=value input '{item}'.", ['item' => $item]));
            }

            [$key, $value] = $pair;
            $key = trim($key);

            if ('' === $key) {
                throw new \InvalidArgumentException('Query key cannot be empty.');
            }

            $result[$key] = $value;
        }

        return $result;
    }

    /**
     * @param array<int,string> $items
     *
     * @return array<string,string>
     */
    private function parseHeaders(array $items): array
    {
        $result = [];

        foreach ($items as $item) {
            $pair = explode(':', $item, 2);
            if (2 !== count($pair)) {
                throw new \InvalidArgumentException(r("Invalid header '{item}'. Use 'Name: value' format.", [
                    'item' => $item,
                ]));
            }

            [$name, $value] = $pair;
            $name = trim($name);

            if ('' === $name) {
                throw new \InvalidArgumentException('Header name cannot be empty.');
            }

            $result[$name] = trim($value);
        }

        return $result;
    }

    /**
     * @param array<string,mixed> $body
     * @param array<string,string> $headers
     * @param array<string,string> $query
     */
    private function formatHttpRequest(array $request, bool $pretty = false): string
    {
        return $this->renderHttpBlock(
            startLine: sprintf(
                '<fg=cyan;options=bold>%s</> <fg=yellow>%s</> <fg=gray>%s</>',
                $this->escape((string) $request['method']),
                $this->escape((string) $request['target']),
                $this->escape((string) $request['protocol']),
            ),
            headers: $request['headers'],
            body: $this->formatBodyForDisplay((string) ($request['body_text'] ?? ''), $pretty),
        );
    }

    private function formatHttpResponse(array $response, bool $pretty = false): string
    {
        return $this->renderHttpBlock(
            startLine: sprintf(
                '<fg=gray>%s</> <fg=%s;options=bold>%d</> <fg=%s>%s</>',
                $this->escape((string) $response['protocol']),
                $this->statusColor((int) $response['status']),
                $response['status'],
                $this->statusColor((int) $response['status']),
                $this->escape((string) $response['reason']),
            ),
            headers: $response['headers'],
            body: $this->formatBodyForDisplay((string) ($response['body_text'] ?? ''), $pretty),
        );
    }

    /**
     * @param array<string,mixed> $body
     * @param array<string,string> $headers
     * @param array<string,string> $query
     *
     * @return array<string,mixed>
     */
    private function buildRequestPayload(
        string $method,
        string $path,
        array $headers,
        array $query,
        array $body,
    ): array {
        $requestHeaders = [
            'Host' => 'localhost',
            'Accept' => 'application/json',
            ...$headers,
        ];

        $requestBody = [] !== $body ? $this->encodeJson($body) : '';

        if ('' !== $requestBody) {
            $requestHeaders['Content-Type'] = 'application/json';
            $requestHeaders['Content-Length'] = (string) strlen($requestBody);
        }

        $requestTarget = rtrim((string) Config::get('api.prefix', ''), '/') . $path;
        if ([] !== $query) {
            $requestTarget .= '?' . http_build_query($query);
        }

        return [
            'method' => $method,
            'path' => $path,
            'target' => $requestTarget,
            'protocol' => 'HTTP/1.1',
            'headers' => $requestHeaders,
            'query' => $query,
            'body' => [] !== $body ? $body : null,
            'body_text' => $requestBody,
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function buildResponsePayload(APIResponse $response): array
    {
        $responseBody = '';
        $responseValue = null;

        if ($response->hasStream()) {
            $response->stream?->rewind();
            $responseBody = (string) $response->stream?->getContents();
            $response->stream?->rewind();
        }

        if ($response->hasBody()) {
            $responseValue = $response->body;
            if ('' === $responseBody) {
                $responseBody = $this->encodeJson($response->body);
            }
        } elseif ('' !== $responseBody) {
            $responseValue = $responseBody;
        }

        $reasonPhrase = new Response(status: $response->status)->getReasonPhrase();

        return [
            'protocol' => 'HTTP/1.1',
            'status' => $response->status->value,
            'reason' => $reasonPhrase,
            'headers' => $response->headers,
            'body' => $responseValue,
            'body_text' => $responseBody,
        ];
    }

    /**
     * @param array<string,mixed> $headers
     */
    private function renderHttpBlock(string $startLine, array $headers, string $body = ''): string
    {
        $lines = [$startLine];

        foreach ($headers as $name => $value) {
            $lines[] = sprintf(
                '<fg=blue>%s</>: %s',
                $this->escape((string) $name),
                $this->escape(is_array($value) ? implode(', ', $value) : (string) $value),
            );
        }

        $lines[] = '';

        if ('' !== $body) {
            $lines[] = $this->escape($body);
            $lines[] = '';
        }

        return implode(PHP_EOL, $lines);
    }

    private function formatBodyForDisplay(string $body, bool $pretty): string
    {
        if ('' === $body || false === $pretty) {
            return $body;
        }

        try {
            $decoded = json_decode($body, flags: JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return $body;
        }

        return $this->encodeJson($decoded, pretty: true);
    }

    private function encodeJson(mixed $value, bool $pretty = false): string
    {
        $flags = JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_IGNORE;

        if (true === $pretty) {
            $flags |= JSON_PRETTY_PRINT;
        }

        return (string) json_encode($value, $flags);
    }

    private function statusColor(int $status): string
    {
        return match (true) {
            $status >= Status::OK->value && $status < Status::MULTIPLE_CHOICES->value => 'green',
            $status >= Status::MULTIPLE_CHOICES->value && $status < Status::BAD_REQUEST->value => 'yellow',
            $status >= Status::BAD_REQUEST->value => 'red',
            default => 'cyan',
        };
    }

    private function escape(string $value): string
    {
        return OutputFormatter::escape($value);
    }
}
