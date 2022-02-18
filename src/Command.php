<?php

declare(strict_types=1);

namespace App;

use App\Libs\Config;
use RuntimeException;
use Symfony\Component\Console\Command\Command as BaseCommand;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Xhgui\Profiler\Profiler;

class Command extends BaseCommand
{
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

        $url = '/cli/' . $this->getName();
        $data['meta']['url'] = $data['meta']['simple_url'] = $url;
        $data['meta']['get'] = $data['meta']['env'] = [];
        $data['meta']['SERVER'] = array_replace_recursive($data['meta']['SERVER'], [
            'APP_VERSION' => Config::get('version'),
            'PHP_VERSION' => PHP_VERSION,
            'PHP_VERSION_ID' => PHP_VERSION_ID,
            'PHP_OS' => PHP_OS,
            'DOCKER' => env('in_docker') ? 'Yes' : 'No',
            'SYSTEM' => php_uname('s') . ' ' . php_uname('r') . ' ' . php_uname('v') . ' ' . php_uname('m'),
            'DOCUMENT_ROOT' => env('IN_DOCKER') ? '/docker/' : '/cli',
            'REMOTE_ADDR' => '127.0.0.1',
            'SERVER_ADDR' => '127.0.0.1',
            'SERVER_NAME' => (env('IN_DOCKER') ? 'docker' : 'cli') . '.watchstate.' . Config::get('version')
        ]);

        foreach ($removeKeys as $key) {
            if (isset($data['meta'][$key])) {
                unset($data['meta'][$key]);
            }
        }

        $profiler->save($data);

        return $status;
    }

    protected function runCommand(InputInterface $input, OutputInterface $output): int
    {
        return self::SUCCESS;
    }
}
