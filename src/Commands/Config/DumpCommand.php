<?php

declare(strict_types=1);

namespace App\Commands\Config;

use App\Command;
use App\Libs\Config;
use RuntimeException;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

class DumpCommand extends Command
{
    private static array $configs = [
        'servers' => 'config' . DS . 'servers.yaml',
        'config' => 'config' . DS . 'config.yaml',
    ];

    protected function configure(): void
    {
        $this->setName('config:dump')
            ->setDescription('Create config files.')
            ->addOption('location', 'l', InputOption::VALUE_OPTIONAL, 'Path to config dir.', Config::get('path'))
            ->addOption('override', 'w', InputOption::VALUE_NONE, 'Override existing file.')
            ->addArgument(
                'type',
                InputArgument::REQUIRED,
                sprintf('Config type to create. Can be one of ( %s )', implode(' or ', array_keys(self::$configs)))
            );
    }

    protected function runCommand(InputInterface $input, OutputInterface $output): int
    {
        $type = $input->getArgument('type');
        $path = $input->getOption('location');

        if (!array_key_exists($type, self::$configs)) {
            throw new RuntimeException(
                sprintf(
                    'Invalid type was given. Expecting ( %s ). but got ( %s ) instead.',
                    implode(' or ', array_keys(self::$configs)),
                    $type
                )
            );
        }

        if (!is_writable($path)) {
            throw new RuntimeException(sprintf('Unable to write to location path. \'%s\'.', $path));
        }

        $file = $path . DS . self::$configs[$type];

        if (file_exists($file) && !$input->getOption('override')) {
            $message = sprintf('File exists at \'%s\'. use [-w, --override] flag to overwrite the file.', $file);
            $output->writeln(sprintf('<error>%s</error>', $message));
            return self::FAILURE;
        }

        $kvSore = [
            'DS' => DS,
            'path' => Config::get('path'),
        ];

        file_put_contents(
            $file,
            str_replace(
                array_map(fn($n) => '%(' . $n . ')', array_keys($kvSore)),
                array_values($kvSore),
                file_get_contents(ROOT_PATH . DS . self::$configs[$type])
            )
        );

        $output->writeln(sprintf('<info>Generated file at \'%s\'.</info>', $file));

        return self::SUCCESS;
    }
}
