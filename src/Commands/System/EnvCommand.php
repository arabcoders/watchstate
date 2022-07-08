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
        $configPath = after(Config::get('path') . '/config/', ROOT_PATH);

        $this->setName(self::ROUTE)
            ->setDescription('Show loaded environment variables.')
            ->setHelp(
                replacer(
                    <<<HELP

This command display the environment variables that was loaded during the run of the tool.
You can load environment variables in many ways. However, the recommended methods are:

<comment># Docker compose file</comment>

You can load environment variables via [<comment>docker-compose.yaml</comment>] file by adding them under the [<comment>environment</comment>] key.

<comment>## [ Example ]</comment>

To enable import task, do the following:

-------------------------------
version: '3.3'
services:
  watchstate:
    image: ghcr.io/arabcoders/watchstate:latest
    restart: unless-stopped
    container_name: watchstate
    <comment>environment:</comment>
      <info>- WS_CRON_IMPORT=1</info>
-------------------------------

<comment># .env file</comment>

We automatically look for [<comment>.env</comment>] in this path [<info>{path}</info>]. The file usually
does not exist unless you have created it.

The file format is simple <info>[KEY<comment>=</comment>VALUE]</info> pair per line.

<comment>## [ Example ]</comment>

To enable import task add the following line to [<comment>.env</comment>] file

-------------------------------
<info>WS_CRON_IMPORT<comment>=</comment>1</info>
-------------------------------

HELP,
                    ['path' => $configPath]
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
