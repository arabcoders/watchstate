<?php

declare(strict_types=1);

namespace App\Commands\System;

use App\Command;
use App\Libs\Attributes\Route\Cli;
use App\Libs\Enums\Http\Status;
use Symfony\Component\Console\Input\InputInterface as iInput;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface as iOutput;

/**
 * Class EnvCommand
 *
 * This command displays the environment variables that were loaded during the execution of the tool.
 */
#[Cli(command: self::ROUTE)]
final class EnvCommand extends Command
{
    public const string ROUTE = 'system:env';

    /**
     * Configure the command.
     */
    protected function configure(): void
    {
        $this->setName(self::ROUTE)
            ->setDescription('Manage Environment Variables.')
            ->addOption('key', 'k', InputOption::VALUE_REQUIRED, 'Key to update.')
            ->addOption('set', 'e', InputOption::VALUE_REQUIRED, 'Value to set.')
            ->addOption('delete', 'd', InputOption::VALUE_NONE, 'Delete key.')
            ->addOption('list', 'l', InputOption::VALUE_NONE, 'List All Supported keys.')
            ->addOption('expose', 'x', InputOption::VALUE_NONE, 'Expose Hidden values.');
    }

    /**
     * Run the command.
     *
     * @param iInput $input The input interface.
     * @param iOutput $output The output interface.
     *
     * @return int The exit code.
     */
    protected function runCommand(iInput $input, iOutput $output): int
    {
        if ($input->getOption('list')) {
            return $this->handleEnvList($input, $output, true);
        }

        if ($input->getOption('key')) {
            return $this->handleEnvUpdate($input, $output);
        }

        return $this->handleEnvList($input, $output, false);
    }

    private function handleEnvUpdate(iInput $input, iOutput $output): int
    {
        $key = strtoupper($input->getOption('key'));

        if (null === $input->getOption('set') && !$input->getOption('delete')) {
            $output->writeln((string)env($key, ''));
            return self::SUCCESS;
        }

        if (true === (bool)$input->getOption('delete')) {
            $response = APIRequest('DELETE', '/system/env/' . $key);
        } else {
            $response = APIRequest('POST', '/system/env/' . $key, ['value' => $input->getOption('set')]);
        }

        if (Status::OK !== $response->status) {
            $output->writeln(r("<error>API error. {status}: {message}</error>", [
                'key' => $key,
                'status' => $response->status->value,
                'message' => ag($response->body, 'error.message', 'Unknown error.')
            ]));
            return self::FAILURE;
        }

        $output->writeln(r("<info>Key '{key}' was {action}.</info>", [
            'key' => $key,
            'action' => true === (bool)$input->getOption('delete') ? 'deleted' : 'updated',
        ]));

        return self::SUCCESS;
    }

    private function handleEnvList(iInput $input, iOutput $output, bool $all = true): int
    {
        $query = [];

        if (false === $all) {
            $query['set'] = 1;
        }

        $response = APIRequest('GET', '/system/env', opts: [
            'query' => $query,
        ]);

        $keys = [];

        $mode = $input->getOption('output');

        $data = ag($response->body, 'data', []);
        foreach ($data as $info) {
            $item = [
                'key' => $info['key'],
                'description' => $info['description'],
                'type' => $info['type'],
                'value' => ag($info, 'value', 'Not set'),
            ];

            if (true === (bool)ag($info, 'mask') && !$input->getOption('expose')) {
                $item['value'] = '*HIDDEN*';
            }

            if ('table' === $mode) {
                unset($item['description']);
            }

            $keys[] = $item;
        }

        $this->displayContent($keys, $output, $mode);

        return self::SUCCESS;
    }
}
