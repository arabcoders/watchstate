<?php

declare(strict_types=1);

namespace App\Commands\Config;

use App\Command;
use App\Libs\Config;
use Exception;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Yaml\Yaml;

class GenerateCommand extends Command
{
    protected function configure(): void
    {
        $this->setName('config:generate')
            ->setDescription('Generate API key for webhook.')
            ->addOption('regenerate', 'w', InputOption::VALUE_NONE, 'Regenerate the API key.');
    }

    /**
     * @throws Exception
     */
    protected function runCommand(InputInterface $input, OutputInterface $output): int
    {
        $yaml = [];
        $config = Config::get('path') . DS . 'config' . DS . 'config.yaml';


        if (file_exists($config)) {
            $yaml = Yaml::parseFile($config);
            if (null !== ag($yaml, 'webhook.apikey') && !$input->getOption('regenerate')) {
                return self::SUCCESS;
            }
        }

        $randomKey = bin2hex(random_bytes(16));

        $output->writeln(sprintf('<info>Your Webhook API key is: %s</info>', $randomKey));

        file_put_contents($config, Yaml::dump(ag_set($yaml, 'webhook.apikey', $randomKey), 8, 2));

        return self::SUCCESS;
    }
}
