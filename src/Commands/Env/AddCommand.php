<?php

declare(strict_types=1);

namespace App\Commands\Env;

use App\Command;
use App\Libs\Attributes\Route\Cli;
use App\Libs\Enums\Http\Method;
use App\Libs\Enums\Http\Status;
use Symfony\Component\Console\Completion\CompletionInput;
use Symfony\Component\Console\Completion\CompletionSuggestions;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface as iInput;
use Symfony\Component\Console\Output\OutputInterface as iOutput;

#[Cli(command: self::ROUTE)]
final class AddCommand extends Command
{
    public const string ROUTE = 'env:add';

    protected function configure(): void
    {
        $this
            ->setName(self::ROUTE)
            ->setDescription('Create or update an environment key.')
            ->addArgument('key', InputArgument::REQUIRED, 'The environment key to create or update.')
            ->addArgument('value', InputArgument::REQUIRED, 'The value to set for the environment key.');
    }

    protected function runCommand(iInput $input, iOutput $output): int
    {
        $key = (string) $input->getArgument('key');
        $value = $input->getArgument('value');

        $response = api_request(
            method: Method::POST,
            path: r('/system/env/{key}', ['key' => strtoupper($key)]),
            json: [
                'value' => $value,
            ],
        );

        if (Status::OK !== $response->status) {
            $output->writeln(r('<error>API error. {status}: {message}</error>', [
                'status' => $response->status->value,
                'message' => ag($response->body, 'error.message', 'Unknown error.'),
            ]));
            return self::FAILURE;
        }

        $this->displayContent($response->body, $output, (bool) $input->getOption('json'));

        return self::SUCCESS;
    }

    public function complete(CompletionInput $input, CompletionSuggestions $suggestions): void
    {
        parent::complete($input, $suggestions);

        if (!$input->mustSuggestArgumentValuesFor('key')) {
            return;
        }

        $currentValue = strtoupper($input->getCompletionValue());
        $suggest = [];

        foreach (require __DIR__ . '/../../../config/env.spec.php' as $column) {
            $key = strtoupper((string) ag($column, 'key', ''));
            if (empty($key)) {
                continue;
            }

            if (!empty($currentValue) && false === str_starts_with($key, $currentValue)) {
                continue;
            }

            $suggest[] = $key;
        }

        $suggestions->suggestValues($suggest);
    }
}
