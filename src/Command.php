<?php

declare(strict_types=1);

namespace App;

use App\Libs\Config;
use App\Libs\Servers\ServerInterface;
use DirectoryIterator;
use RuntimeException;
use Symfony\Component\Console\Command\Command as BaseCommand;
use Symfony\Component\Console\Command\LockableTrait;
use Symfony\Component\Console\Completion\CompletionInput;
use Symfony\Component\Console\Completion\CompletionSuggestions;
use Symfony\Component\Console\Helper\Table;
use Symfony\Component\Console\Helper\TableSeparator;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Yaml\Yaml;
use Xhgui\Profiler\Profiler;

class Command extends BaseCommand
{
    use LockableTrait;

    protected array $outputs = [
        'table',
        'json',
        'yaml',
    ];

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
            if (!$this->lock(getAppVersion() . ':' . $this->getName())) {
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

    protected function getBackend(string $name, array $config = []): ServerInterface
    {
        if (null === Config::get("servers.{$name}.type", null)) {
            throw new RuntimeException(sprintf('No backend named \'%s\' was found.', $name));
        }

        $default = Config::get("servers.{$name}");
        $default['name'] = $name;

        return makeServer(array_merge_recursive($default, $config), $name);
    }

    protected function displayContent(array $content, OutputInterface $output, string $mode = 'json'): void
    {
        if ('json' === $mode) {
            $output->writeln(
                json_encode(
                    value: $content,
                    flags: JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_IGNORE
                )
            );
        } elseif ('table' === $mode) {
            $list = [];

            foreach ($content as $_ => $item) {
                if (false === is_array($item)) {
                    $item = [$_ => $item];
                }

                $subItem = [];

                foreach ($item as $key => $leaf) {
                    if (true === is_array($leaf)) {
                        continue;
                    }
                    $subItem[$key] = $leaf;
                }

                $list[] = $subItem;
                $list[] = new TableSeparator();
            }

            if (!empty($list)) {
                array_pop($list);
                (new Table($output))->setStyle('box')->setHeaders(
                    array_map(fn($title) => is_string($title) ? ucfirst($title) : $title, array_keys($list[0]))
                )->setRows($list)->render();
            }
        } else {
            $output->writeln(Yaml::dump($content, 8, 2));
        }
    }

    public function complete(CompletionInput $input, CompletionSuggestions $suggestions): void
    {
        if ($input->mustSuggestOptionValuesFor('config')) {
            $currentValue = $input->getCompletionValue();

            $suggest = [];

            foreach (new DirectoryIterator(getcwd()) as $name) {
                if (!$name->isFile()) {
                    continue;
                }

                if (empty($currentValue) || str_starts_with($name->getFilename(), $currentValue)) {
                    $suggest[] = $name->getFilename();
                }
            }

            $suggestions->suggestValues($suggest);
        }

        if ($input->mustSuggestOptionValuesFor('servers-filter') ||
            $input->mustSuggestArgumentValuesFor('server') ||
            $input->mustSuggestArgumentValuesFor('backend')) {
            $currentValue = $input->getCompletionValue();

            $suggest = [];

            foreach (array_keys(Config::get('servers', [])) as $name) {
                if (true === str_contains($currentValue, ',')) {
                    $text = explode(',', $currentValue);
                    $currentValue = array_pop($text);
                }

                if (empty($currentValue) || str_starts_with($name, $currentValue)) {
                    $suggest[] = $name;
                }
            }

            $suggestions->suggestValues($suggest);
        }
    }
}
