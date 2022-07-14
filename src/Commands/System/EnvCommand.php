<?php

declare(strict_types=1);

namespace App\Commands\System;

use App\Command;
use App\Libs\Config;
use App\Libs\Routable;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

#[Routable(command: self::ROUTE)]
final class EnvCommand extends Command
{
    public const ROUTE = 'system:env';

    protected function configure(): void
    {
        $this->setName(self::ROUTE)
            ->setDescription('Show loaded environment variables.')
            ->setHelp(
                r(
                    <<<HELP

                    This command display the environment variables that was loaded execution of the tool.

                    -------------------------------
                    <notice>[ Environment variables rules ]</notice>
                    -------------------------------

                    * the key MUST be in CAPITAL LETTERS. For example [<flag>WS_CRON_IMPORT</flag>].
                    * the key MUST start with [<flag>WS_</flag>]. For example [<flag>WS_CRON_EXPORT</flag>].
                    * the value is usually simple type, usually string unless otherwise stated.
                    * the key SHOULD attempt to mirror the key path in default config, If not applicable or otherwise impossible it
                    should then use an approximate path.

                    -------
                    <notice>[ FAQ ]</notice>
                    -------

                    <question># How to load environment variables?</question>

                    You can load environment variables in many ways. However, the recommended methods are:

                    <question>(1) Via Docker compose file</>

                    You can load environment variables via [<comment>docker-compose.yaml</comment>] file by adding them under the [<comment>environment</comment>] key.
                    For example, to enable import task, do the following:

                    -------------------------------
                    version: '3.3'
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
                        'path' => after(Config::get('path') . '/config', ROOT_PATH)
                    ]
                )
            );
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
}
