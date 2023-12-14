<?php

declare(strict_types=1);

namespace App;

use App\Backends\Common\ClientInterface as iClient;
use App\Libs\Config;
use Closure;
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

    /**
     * The DISPLAY_OUTPUT constant represents the available output formats for displaying data.
     *
     * It is an array containing three possible formats: table, json, and yaml.
     *
     * @var array<string>
     */
    public const DISPLAY_OUTPUT = [
        'table',
        'json',
        'yaml',
    ];

    /**
     * Execute the command.
     *
     * @param InputInterface $input The input object.
     * @param OutputInterface $output The output object.
     *
     * @return int The command exit status.
     * @throws RuntimeException If the profiler was enabled and the run was unsuccessful.
     */
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        if ($input->hasOption('context') && true === $input->getOption('context')) {
            Config::save('logs.context', true);
        }

        if ($input->hasOption('no-context') && true === $input->getOption('no-context')) {
            Config::save('logs.context', false);
        }

        if ($input->hasOption('trace') && true === $input->getOption('trace')) {
            Config::save('logs.context', true);
        }

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
        $inContainer = inContainer();

        $url = '/cli/' . $this->getName();
        $data['meta']['url'] = $data['meta']['simple_url'] = $url;
        $data['meta']['get'] = $data['meta']['env'] = [];
        $data['meta']['SERVER'] = array_replace_recursive($data['meta']['SERVER'], [
            'APP_VERSION' => $appVersion,
            'PHP_VERSION' => PHP_VERSION,
            'PHP_VERSION_ID' => PHP_VERSION_ID,
            'PHP_OS' => PHP_OS,
            'CONTAINER' => $inContainer ? 'Yes' : 'No',
            'SYSTEM' => php_uname('s') . ' ' . php_uname('r') . ' ' . php_uname('v') . ' ' . php_uname('m'),
            'DOCUMENT_ROOT' => $inContainer ? '/container/' : '/cli',
            'REMOTE_ADDR' => '127.0.0.1',
            'SERVER_ADDR' => '127.0.0.1',
            'SERVER_NAME' => ($inContainer ? 'container' : 'cli') . '.watchstate.' . $appVersion
        ]);

        foreach ($removeKeys as $key) {
            if (isset($data['meta'][$key])) {
                unset($data['meta'][$key]);
            }
        }

        $profiler->save($data);

        return $status;
    }

    /**
     * Executes the provided closure in a single instance, ensuring that only one instance of the command is running at a time.
     *
     * @param Closure $closure The closure to be executed.
     * @param OutputInterface $output The OutputInterface instance for writing output messages.
     *
     * @return int The return value of the closure.
     */
    protected function single(Closure $closure, OutputInterface $output): int
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

    /**
     * Runs the command and returns the return value.
     *
     * @param InputInterface $input The InputInterface instance for retrieving input data.
     * @param OutputInterface $output The OutputInterface instance for writing output messages.
     *
     * @return int The return value of the command execution.
     */
    protected function runCommand(InputInterface $input, OutputInterface $output): int
    {
        return self::SUCCESS;
    }

    /**
     * Check given backends file.
     *
     * @param string $config custom servers.yaml file.
     *
     * @return string the config file path.
     * @throws RuntimeException if there is problem with given config.
     */
    protected function checkCustomBackendsFile(string $config): string
    {
        if (!file_exists($config) || !is_file($config)) {
            throw new RuntimeException(
                r('Config file [{config}] does not exists.', [
                    'config' => $config
                ])
            );
        }

        if (!is_readable($config)) {
            throw new RuntimeException(
                r('Unable to read config file [{config}]. (Check Permissions)', [
                    'config' => $config
                ])
            );
        }

        if (!is_writable($config)) {
            throw new RuntimeException(
                r('Unable to edit config file [{config}]. (Check Permissions)', [
                    'config' => $config
                ])
            );
        }

        return $config;
    }

    /**
     * Retrieves the backend client for the specified name.
     *
     * @param string $name The name of the backend.
     * @param array $config (Optional) Override the default configuration for the backend.
     *
     * @return iClient The backend client instance.
     * @throws RuntimeException If no backend with the specified name is found.
     */
    protected function getBackend(string $name, array $config = []): iClient
    {
        if (null === Config::get("servers.{$name}.type", null)) {
            throw new RuntimeException(r('No backend named [{backend}] was found.', ['backend' => $name]));
        }

        $default = Config::get("servers.{$name}");
        $default['name'] = $name;

        return makeBackend(array_replace_recursive($default, $config), $name);
    }

    /**
     * Displays the content in the specified mode.
     *
     * @param array $content The content to display.
     * @param OutputInterface $output The OutputInterface instance for writing output messages.
     * @param string $mode The display mode. Default is 'json'.
     */
    protected function displayContent(array $content, OutputInterface $output, string $mode = 'json'): void
    {
        switch ($mode) {
            case 'json':
                $output->writeln(
                    json_encode(
                        value: $content,
                        flags: JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_IGNORE
                    )
                );
                break;
            case 'table':
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
                    (new Table($output))
                        ->setStyle(name: 'box')
                        ->setHeaders(
                            array_map(
                                callback: fn($title) => is_string($title) ? ucfirst($title) : $title,
                                array: array_keys($list[0])
                            )
                        )
                        ->setRows(rows: $list)
                        ->render();
                }
                break;
            case 'yaml':
            default:
                $output->writeln(Yaml::dump(input: $content, inline: 8, indent: 2));
                break;
        }
    }

    /**
     * Completes the input by suggesting values for different options and arguments.
     *
     * @param CompletionInput $input The CompletionInput instance containing the input data.
     * @param CompletionSuggestions $suggestions The CompletionSuggestions instance for suggesting values.
     */
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

        if ($input->mustSuggestOptionValuesFor('select-backends') || $input->mustSuggestArgumentValuesFor('backend')) {
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

        if ($input->mustSuggestOptionValuesFor('output')) {
            $currentValue = $input->getCompletionValue();

            $suggest = [];

            foreach (static::DISPLAY_OUTPUT as $name) {
                if (empty($currentValue) || str_starts_with($name, $currentValue)) {
                    $suggest[] = $name;
                }
            }

            $suggestions->suggestValues($suggest);
        }
    }
}
