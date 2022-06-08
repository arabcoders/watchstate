<?php

declare(strict_types=1);

namespace App\Commands\System;

use App\Command;
use Symfony\Component\Console\Completion\CompletionInput;
use Symfony\Component\Console\Completion\CompletionSuggestions;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

final class EnvCommand extends Command
{
    protected function configure(): void
    {
        $this->setName('system:env')
            ->addOption(
                'output',
                'o',
                InputOption::VALUE_OPTIONAL,
                sprintf('Output mode. Can be [%s].', implode(', ', $this->outputs)),
                $this->outputs[0],
            )
            ->setDescription('Dump loaded environment variables.');
    }

    protected function runCommand(InputInterface $input, OutputInterface $output): int
    {
        $mode = $input->getOption('output');
        $keys = [];

        foreach (getenv() as $key => $val) {
            if (false === str_starts_with($key, 'WS_')) {
                continue;
            }

            $keys[$key] = $val;
        }

        if ('table' === $mode) {
            $list = [];

            foreach ($keys as $key => $val) {
                $list[] = ['key' => $key, 'value' => $val];
            }

            $keys = $list;
        }

        $this->displayContent($keys, $output, $mode);

        return self::SUCCESS;
    }

    public function complete(CompletionInput $input, CompletionSuggestions $suggestions): void
    {
        parent::complete($input, $suggestions);

        $methods = [
            'output' => 'outputs',
        ];

        foreach ($methods as $key => $of) {
            if ($input->mustSuggestOptionValuesFor($key)) {
                $currentValue = $input->getCompletionValue();

                $suggest = [];

                foreach ($this->{$of} as $name) {
                    if (empty($currentValue) || str_starts_with($name, $currentValue)) {
                        $suggest[] = $name;
                    }
                }

                $suggestions->suggestValues($suggest);
            }
        }
    }

}
