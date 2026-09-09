<?php

declare(strict_types=1);

namespace App\Commands\Env;

use App\Command;
use App\Libs\Attributes\Route\Cli;
use App\Libs\Enums\Http\Method;
use App\Libs\Enums\Http\Status;
use Symfony\Component\Console\Formatter\OutputFormatter;
use Symfony\Component\Console\Input\InputInterface as iInput;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface as iOutput;

#[Cli(command: self::ROUTE)]
final class ListCommand extends Command
{
    public const string ROUTE = 'env:list';

    protected function configure(): void
    {
        $this
            ->setName(self::ROUTE)
            ->setDescription('List environment keys.')
            ->addOption(
                'key',
                'k',
                InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY,
                'Filter environment keys by exact, partial, or glob match. The WS_ prefix is optional.',
            )
            ->addOption('set', 's', InputOption::VALUE_NONE, 'Only show keys that are currently set.')
            ->addOption('expose', 'x', InputOption::VALUE_NONE, 'Expose masked values in the output.');
    }

    protected function runCommand(iInput $input, iOutput $output): int
    {
        $keys = array_map(
            static fn(mixed $key): string => strtoupper(trim((string) $key)),
            (array) $input->getOption('key'),
        );
        if (true === in_array('', $keys, true)) {
            $output->writeln('<error>Environment key filter cannot be empty.</error>');
            return self::FAILURE;
        }
        $keys = array_values(array_unique($keys));

        $response = api_request(
            method: Method::GET,
            path: '/system/env',
            opts: [
                'query' => [
                    'set' => (bool) $input->getOption('set'),
                ],
            ],
        );

        if (Status::OK !== $response->status) {
            $output->writeln(r('<error>API error. {status}: {message}</error>', [
                'status' => $response->status->value,
                'message' => ag($response->body, 'error.message', 'Unknown error.'),
            ]));
            return self::FAILURE;
        }

        $json = (bool) $input->getOption('json');
        $items = (array) ag($response->body, 'data', []);
        if ([] !== $keys) {
            $items = $this->filterItems($items, $keys);
        }
        $data = $this->sanitizeData(
            items: $items,
            expose: (bool) $input->getOption('expose'),
        );
        $body = $response->body;
        $body['data'] = $data;
        $file = ag($response->body, 'file');

        if (!$json) {
            if (!empty($file)) {
                $output->writeln(r('<info>Env file:</info> <comment>{file}</comment>', ['file' => $file]));
            }

            if (empty($data)) {
                $output->writeln('<comment>No environment keys matched.</comment>');
                return self::SUCCESS;
            }

            foreach (array_values($body['data']) as $index => $item) {
                $value = ag($item, 'value', ag($item, 'config_value'));

                $value = match (true) {
                    null === $value => 'null',
                    true === is_bool($value) => $value ? 'true' : 'false',
                    true === is_array($value) => (string) json_encode(
                        $value,
                        JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_IGNORE,
                    ),
                    default => (string) $value,
                };
                $output->writeln('<info>' . ($index + 1) . '. ' . OutputFormatter::escape((string) ag($item, 'key', '?')) . '</info>');
                $output->writeln('   ' . OutputFormatter::escape('value: ' . $value));
                $output->writeln('   ' . OutputFormatter::escape('description: ' . (string) ag($item, 'description', '')));

                if (($index + 1) < count($body['data'])) {
                    $output->writeln('');
                }
            }
            return self::SUCCESS;
        }

        $this->displayContent($body, $output, true);

        return self::SUCCESS;
    }

    private function sanitizeData(array $items, bool $expose): array
    {
        if ($expose) {
            return $items;
        }

        return array_map(static function (array $item): array {
            if (true !== (bool) ag($item, 'mask', false)) {
                return $item;
            }

            if (true === ag_exists($item, 'value') && null !== $item['value']) {
                $item['value'] = '*HIDDEN*';
            }

            if (true === ag_exists($item, 'config_value') && null !== $item['config_value']) {
                $item['config_value'] = '*HIDDEN*';
            }

            return $item;
        }, $items);
    }

    private function filterItems(array $items, array $keys): array
    {
        $selected = [];

        foreach ($keys as $key) {
            $key = str_starts_with($key, 'WS_') ? substr($key, 3) : $key;
            $name = static function (array $item): string {
                $candidate = strtoupper((string) ag($item, 'key', ''));

                return str_starts_with($candidate, 'WS_') ? substr($candidate, 3) : $candidate;
            };
            $matches = array_values(array_filter(
                $items,
                static fn(array $item): bool => $name($item) === $key,
            ));

            if ([] === $matches) {
                $matches = array_values(array_filter(
                    $items,
                    static fn(array $item): bool => str_contains($name($item), $key),
                ));
            }

            if ([] === $matches) {
                $matches = array_values(array_filter(
                    $items,
                    static fn(array $item): bool => fnmatch($key, $name($item)),
                ));
            }

            foreach ($matches as $match) {
                $selected[(string) ag($match, 'key', '')] = true;
            }
        }

        return array_values(array_filter(
            $items,
            static fn(array $item): bool => isset($selected[(string) ag($item, 'key', '')]),
        ));
    }
}
