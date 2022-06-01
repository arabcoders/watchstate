<?php

declare(strict_types=1);

namespace App\Commands\Backend\Users;

use App\Command;
use App\Libs\Config;
use App\Libs\Options;
use RuntimeException;
use Symfony\Component\Console\Completion\CompletionInput;
use Symfony\Component\Console\Completion\CompletionSuggestions;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Yaml\Yaml;
use Throwable;

final class ListCommand extends Command
{
    protected function configure(): void
    {
        $this->setName('backend:users:list')
            ->setDescription('Get backend users list.')
            ->addOption(
                'output',
                'o',
                InputOption::VALUE_OPTIONAL,
                sprintf('Output mode. Can be [%s].', implode(', ', $this->outputs)),
                $this->outputs[0],
            )
            ->addOption('with-tokens', 't', InputOption::VALUE_NONE, 'Include access tokens in response.')
            ->addOption('include-raw-response', null, InputOption::VALUE_NONE, 'Include unfiltered raw response.')
            ->addOption('config', 'c', InputOption::VALUE_REQUIRED, 'Use Alternative config file.')
            ->addArgument('backend', InputArgument::REQUIRED, 'Backend name.');
    }

    protected function runCommand(InputInterface $input, OutputInterface $output): int
    {
        $mode = $input->getOption('output');
        $backend = $input->getArgument('backend');

        // -- Use Custom servers.yaml file.
        if (($config = $input->getOption('config'))) {
            try {
                Config::save('servers', Yaml::parseFile($this->checkCustomServersFile($config)));
            } catch (RuntimeException $e) {
                $arr = [
                    'error' => $e->getMessage()
                ];
                $this->displayContent('table' === $mode ? [$arr] : $arr, $output, $mode);
                return self::FAILURE;
            }
        }

        try {
            $opts = [];

            if ($input->getOption('with-tokens')) {
                $opts['tokens'] = true;
            }

            if ($input->getOption('include-raw-response')) {
                $opts[Options::RAW_RESPONSE] = true;
            }

            $libraries = $this->getBackend($backend)->getUsersList(opts: $opts);

            if (count($libraries) < 1) {
                $arr = [
                    'info' => sprintf('%s: No libraries were found.', $backend),
                ];
                $this->displayContent('table' === $mode ? [$arr] : $arr, $output, $mode);
                return self::FAILURE;
            }

            if ('table' === $mode) {
                $list = [];

                foreach ($libraries as $item) {
                    foreach ($item as $key => $val) {
                        if (false === is_bool($val)) {
                            continue;
                        }
                        $item[$key] = $val ? 'Yes' : 'No';
                    }
                    $list[] = $item;
                }

                $libraries = $list;
            }

            $this->displayContent($libraries, $output, $mode);

            return self::SUCCESS;
        } catch (Throwable $e) {
            $arr = [
                'error' => sprintf('%s: %s', $backend, $e->getMessage()),
            ];
            if ('table' !== $mode) {
                $arr += [
                    'file' => $e->getFile(),
                    'line' => $e->getLine(),
                ];
            }
            $this->displayContent('table' === $mode ? [$arr] : $arr, $output, $mode);
            return self::FAILURE;
        }
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
