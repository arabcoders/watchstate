<?php

declare(strict_types=1);

namespace App\Commands\Config;

use App\Command;
use Symfony\Component\Console\Helper\Table;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

final class EnvCommand extends Command
{
    protected function configure(): void
    {
        $this->setName('config:env')
            ->setDescription('Dump registered environment variables.');
    }

    protected function runCommand(InputInterface $input, OutputInterface $output): int
    {
        $keys = [];

        foreach (getenv() as $key => $val) {
            if (!str_starts_with($key, 'WS_')) {
                continue;
            }
            $keys[] = [$key, $val];
        }

        (new Table($output))->setStyle('box')
            ->setHeaders(['Key', 'Value'])
            ->setRows($keys)
            ->render();

        return self::SUCCESS;
    }

}
