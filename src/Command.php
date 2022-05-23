<?php

declare(strict_types=1);

namespace App;

use App\Libs\Config;
use RuntimeException;
use Symfony\Component\Console\Command\Command as BaseCommand;
use Symfony\Component\Console\Command\LockableTrait;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Xhgui\Profiler\Profiler;

class Command extends BaseCommand
{
    use LockableTrait;

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        if (!$input->hasOption('profile') || !$input->getOption('profile')) {
            return $this->runCommand($input, $output);
        }

        $profiler = new Profiler(Config::get('debug.profiler.options', []));

        $profiler->enable();

        $status = $this->runCommand($input, $output);

        $data = $profiler->disable();

        if (empty($data)) {
            throw new RuntimeException('The profiler run was unsuccessful. No data was returned.');
        }

        $removeKeys = [
            'HTTP_USER_AGENT',
            'PHP_AUTH_USER',
            'REMOTE_USER',
            'UNIQUE_ID'
        ];

        $appVersion = getAppVersion();

        $url = '/cli/' . $this->getName();
        $data['meta']['url'] = $data['meta']['simple_url'] = $url;
        $data['meta']['get'] = $data['meta']['env'] = [];
        $data['meta']['SERVER'] = array_replace_recursive($data['meta']['SERVER'], [
            'APP_VERSION' => $appVersion,
            'PHP_VERSION' => PHP_VERSION,
            'PHP_VERSION_ID' => PHP_VERSION_ID,
            'PHP_OS' => PHP_OS,
            'DOCKER' => env('IN_DOCKER') ? 'Yes' : 'No',
            'SYSTEM' => php_uname('s') . ' ' . php_uname('r') . ' ' . php_uname('v') . ' ' . php_uname('m'),
            'DOCUMENT_ROOT' => env('IN_DOCKER') ? '/docker/' : '/cli',
            'REMOTE_ADDR' => '127.0.0.1',
            'SERVER_ADDR' => '127.0.0.1',
            'SERVER_NAME' => (env('IN_DOCKER') ? 'docker' : 'cli') . '.watchstate.' . $appVersion
        ]);

        foreach ($removeKeys as $key) {
            if (isset($data['meta'][$key])) {
                unset($data['meta'][$key]);
            }
        }

        $profiler->save($data);

        return $status;
    }

    protected function single(\Closure $closure, OutputInterface $output): int
    {
        try {
            if (!$this->lock($this->getName())) {
                $output->writeln(
                    sprintf(
                        '<error>The command \'%s\' is already running in another process.</error>',
                        $this->getName()
                    )
                );

                return self::SUCCESS;
            }
            return $closure();
        } finally {
            $this->release();
        }
    }

    protected function runCommand(InputInterface $input, OutputInterface $output): int
    {
        return self::SUCCESS;
    }

    /**
     * Check Given servers config.
     *
     * @param string $config custom servers.yaml file.
     * @return string
     *
     * @throws RuntimeException if there is problem with given config.
     */
    protected function checkCustomServersFile(string $config): string
    {
        if (!file_exists($config) || !is_file($config)) {
            throw new RuntimeException(
                sprintf('ERROR: Config file \'%s\' does not exists.', $config)
            );
        }

        if (!is_readable($config)) {
            throw new RuntimeException(
                sprintf(
                    'ERROR: Unable to read config file \'%s\'. (Check Permissions)',
                    $config
                )
            );
        }

        if (!is_writable($config)) {
            throw new RuntimeException(
                sprintf(
                    'ERROR: Unable to edit config file \'%s\'. (Check Permissions)',
                    $config
                )
            );
        }

        return $config;
    }
}
