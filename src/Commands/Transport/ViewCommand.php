<?php

declare(strict_types=1);

namespace App\Commands\Transport;

use App\Libs\Attributes\Route\Cli;
use App\Libs\Enums\Http\Method;
use App\Libs\Enums\Http\Status;
use InvalidArgumentException;
use Symfony\Component\Console\Completion\CompletionInput;
use Symfony\Component\Console\Completion\CompletionSuggestions;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

#[Cli(command: self::ROUTE)]
final class ViewCommand extends AbstractTransportCommand
{
    public const string ROUTE = 'transport:queue:view';

    protected function configure(): void
    {
        $this
            ->setName(self::ROUTE)
            ->setDescription('Show a detailed transport queue envelope.')
            ->addArgument('id', InputArgument::REQUIRED, 'Envelope UUID or latest.')
            ->addOption(
                'section',
                null,
                InputOption::VALUE_REQUIRED,
                'Section to render: summary, data, options, entry, all.',
                'summary,data,options',
            );
    }

    protected function runCommand(InputInterface $input, OutputInterface $output): int
    {
        $id = $this->resolveId(trim((string) $input->getArgument('id')), $output);
        if (null === $id) {
            return self::FAILURE;
        }

        try {
            $sections = $this->parseSections((string) $input->getOption('section'));
        } catch (InvalidArgumentException $e) {
            $output->writeln('<error>' . $this->escape($e->getMessage()) . '</error>');
            return self::FAILURE;
        }

        $response = api_request(Method::GET, r('/system/transport/queue/{id}', ['id' => $id]));
        if (Status::OK !== $response->status) {
            return $this->apiError($output, $response);
        }

        $mode = $this->outputMode($input);
        if ('table' !== $mode) {
            $this->displayContent((array) $response->body, $output, $mode);
            return self::SUCCESS;
        }

        $this->renderView((array) $response->body, $sections, $output);

        return self::SUCCESS;
    }

    public function complete(CompletionInput $input, CompletionSuggestions $suggestions): void
    {
        parent::complete($input, $suggestions);

        if ($input->mustSuggestArgumentValuesFor('id')) {
            $suggestions->suggestValues(['latest']);
        }
    }

    private function resolveId(string $value, OutputInterface $output): ?string
    {
        if ('' === $value) {
            $output->writeln('<error>Transport envelope id is required.</error>');
            return null;
        }

        if ('latest' === strtolower($value)) {
            $response = api_request(Method::GET, '/system/transport/queue', opts: [
                'query' => ['page' => 1, 'perpage' => 1],
            ]);

            if (Status::OK !== $response->status) {
                $this->apiError($output, $response);
                return null;
            }

            $id = trim((string) ag($response->body, 'items.0.id', ''));
            if ('' === $id) {
                $output->writeln('<error>No transport envelopes are available.</error>');
                return null;
            }

            return $id;
        }

        if (1 === preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[1-8][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i', $value)) {
            return $value;
        }

        $matches = [];
        $page = 1;
        do {
            $response = api_request(Method::GET, '/system/transport/queue', opts: [
                'query' => ['page' => $page, 'perpage' => 100],
            ]);

            if (Status::OK !== $response->status) {
                $this->apiError($output, $response);
                return null;
            }

            foreach ((array) ag($response->body, 'items', []) as $item) {
                $itemId = (string) ag($item, 'id', '');
                if (str_starts_with($itemId, $value)) {
                    $matches[$itemId] = true;
                }
            }

            $page = (int) ag($response->body, 'paging.next', 0);
        } while ($page > 0);

        if (0 === count($matches)) {
            $output->writeln(r('<error>No transport envelope matched id [{id}].</error>', ['id' => $value]));
            return null;
        }

        if (1 !== count($matches)) {
            $output->writeln(r('<error>Short transport id [{id}] is ambiguous.</error>', ['id' => $value]));
            return null;
        }

        return (string) array_key_first($matches);
    }
}
