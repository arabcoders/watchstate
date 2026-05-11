<?php

declare(strict_types=1);

namespace App\Commands\Events;

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
final class ViewCommand extends AbstractEventCommand
{
    public const string ROUTE = 'events:view';

    protected function configure(): void
    {
        $this
            ->setName(self::ROUTE)
            ->setDescription('Show a detailed event entry.')
            ->addArgument('id', InputArgument::REQUIRED, 'Event UUID, short id, or latest')
            ->addOption(
                'section',
                null,
                InputOption::VALUE_REQUIRED,
                'Section to render: summary, data, options, logs, entry, all.',
                'summary,data,options,logs',
            );
    }

    protected function runCommand(InputInterface $input, OutputInterface $output): int
    {
        $id = $this->resolveEventId((string) $input->getArgument('id'), $output);
        if (null === $id) {
            return self::FAILURE;
        }

        try {
            $sections = $this->parseSections((string) $input->getOption('section'));
        } catch (InvalidArgumentException $e) {
            $output->writeln('<error>' . $this->escape($e->getMessage()) . '</error>');
            return self::FAILURE;
        }

        $response = api_request(Method::GET, r('/system/events/{id}', ['id' => $id]));
        if (Status::OK !== $response->status) {
            return $this->apiError($output, $response);
        }

        $mode = $this->outputMode($input);
        if ('table' !== $mode) {
            $this->displayContent($response->body, $output, $mode);
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
}
