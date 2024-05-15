<?php

declare(strict_types=1);

namespace App\Commands\System;

use App\Command;
use App\Libs\Attributes\Route\Cli;
use App\Libs\Config;
use App\Libs\EnvFile;
use App\Libs\Exceptions\InvalidArgumentException;
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

    public const array EXEMPT_KEYS = ['HTTP_PORT', 'TZ'];

    /**
     * Configure the command.
     */
    protected function configure(): void
    {
        $this->setName(self::ROUTE)
            ->setDescription('Show/edit environment variables.')
            ->addOption(
                'envfile',
                null,
                InputOption::VALUE_REQUIRED,
                'Environment file.',
                Config::get('path') . '/config/.env'
            )
            ->addOption('key', 'k', InputOption::VALUE_REQUIRED, 'Key to update.')
            ->addOption('set', 'e', InputOption::VALUE_REQUIRED, 'Value to set.')
            ->addOption('delete', 'd', InputOption::VALUE_NONE, 'Delete key.')
            ->addOption('list', 'l', InputOption::VALUE_NONE, 'List All Supported keys.')
            ->setHelp(
                r(
                    <<<HELP

                    This command display the environment variables that was loaded during execution of the tool.

                    -------------------------------
                    <notice>[ Environment variables rules ]</notice>
                    -------------------------------

                    * the key MUST be in CAPITAL LETTERS. For example [<flag>WS_CRON_IMPORT</flag>].
                    * the key MUST start with [<flag>WS_</flag>]. For example [<flag>WS_CRON_EXPORT</flag>].
                    * the value is usually simple type, usually string unless otherwise stated.
                    * the key SHOULD attempt to mirror the key path in default config, If not applicable or otherwise impossible it
                    should then use an approximate path.

                    * The following keys are exempt from the rules: [<flag>{exempt_keys}</flag>].

                    -------
                    <notice>[ FAQ ]</notice>
                    -------

                    <question># How to load environment variables?</question>

                    You can load environment variables in many ways. However, the recommended methods are:

                    <question>(1) Via Docker compose file</>

                    You can load environment variables via [<comment>compose.yaml</comment>] file by adding them under the [<comment>environment</comment>] key.
                    For example, to enable import task, do the following:

                    -------------------------------
                    services:
                      watchstate:
                        image: ghcr.io/arabcoders/watchstate:latest
                        restart: unless-stopped
                        container_name: watchstate
                        <flag>environment:</flag>
                          - <flag>WS_CRON_IMPORT</flag>=<value>1</value>
                    -------------------------------

                    <question>(2) Via .env file</question>

                    We automatically look for [<value>.env</value>] in this path [<value>{path}</value>]. The file usually
                    does not exist unless you have created it.

                    The file format is simple <flag>key</flag>=<value>value</value> per line. For example, to enable import task, edit the [<value>.env</value>] and add

                    -------------------------------
                    <flag>WS_CRON_IMPORT</flag>=<value>1</value>
                    -------------------------------

                    HELP,
                    [
                        'path' => after(Config::get('path') . '/config', ROOT_PATH),
                        'exempt_keys' => implode(', ', self::EXEMPT_KEYS),
                    ]
                )
            );
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
            return $this->handleEnvList($input, $output);
        }

        if ($input->getOption('key')) {
            return $this->handleEnvUpdate($input, $output);
        }
        $mode = $input->getOption('output');
        $keys = [];

        foreach (getenv() as $key => $val) {
            if (false === str_starts_with($key, 'WS_') && false === in_array($key, self::EXEMPT_KEYS)) {
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

    private function handleEnvUpdate(iInput $input, iOutput $output): int
    {
        $key = strtoupper($input->getOption('key'));

        if (false === str_starts_with($key, 'WS_')) {
            $output->writeln(r("<error>Invalid key '{key}'. Key must start with 'WS_'.</error>", ['key' => $key]));
            return self::FAILURE;
        }

        if (!$input->getOption('set') && !$input->getOption('delete')) {
            $output->writeln((string)env($key, ''));
            return self::SUCCESS;
        }

        $envFile = new EnvFile($input->getOption('envfile'), create: true);

        if (true === (bool)$input->getOption('delete')) {
            $envFile->remove($key);
        } else {
            $spec = $this->getSpec($key);

            if (empty($spec)) {
                $output->writeln(
                    r(
                        "<error>Invalid key '{key}' was used. Run the command with --list flag to see list of supported vars.</error>",
                        ['key' => $key]
                    )
                );
                return self::FAILURE;
            }

            try {
                if (!$this->checkValue($spec, $input->getOption('set'))) {
                    $output->writeln(r("<error>Invalid value for '{key}'.</error>", ['key' => $key]));
                    return self::FAILURE;
                }
            } catch (InvalidArgumentException $e) {
                $output->writeln(r("<error>Value validation for '{key}' failed. {message}</error>", [
                    'key' => $key,
                    'message' => $e->getMessage()
                ]));
                return self::FAILURE;
            }

            $envFile->set($key, $input->getOption('set'));
        }

        $output->writeln(r("<info>Key '{key}' {operation} successfully.</info>", [
            'key' => $key,
            'operation' => (true === (bool)$input->getOption('delete')) ? 'deleted' : 'updated'
        ]));

        $envFile->persist();

        return self::SUCCESS;
    }

    private function handleEnvList(iInput $input, iOutput $output): int
    {
        $spec = require __DIR__ . '/../../../config/env.spec.php';

        $keys = [];

        $mode = $input->getOption('output');

        foreach ($spec as $info) {
            $item = [
                'key' => $info['key'],
                'description' => $info['description'],
                'type' => $info['type'],
                'value' => env($info['key'], 'Not Set')
            ];

            $keys[] = $item;
        }

        $this->displayContent($keys, $output, $mode);

        return self::SUCCESS;
    }

    private function getSpec(string $key): array
    {
        $spec = require __DIR__ . '/../../../config/env.spec.php';

        foreach ($spec as $info) {
            if ($info['key'] !== $key) {
                continue;
            }
            return $info;
        }

        return [];
    }

    /**
     * Check if the value is valid.
     *
     * @param array $spec the specification for the key.
     * @param mixed $value the value to check.
     *
     * @return bool true if the value is valid, false otherwise.
     */
    private function checkValue(array $spec, mixed $value): bool
    {
        if (str_contains($value, ' ') && (!str_starts_with($value, '"') || !str_ends_with($value, '"'))) {
            throw new InvalidArgumentException(
                r("The value for '{key}' must be \"quoted string\", as it contains a space.", [
                    'key' => ag($spec, 'key'),
                ])
            );
        }

        if (ag_exists($spec, 'validate')) {
            return (bool)$spec['validate']($value);
        }

        return true;
    }

}
