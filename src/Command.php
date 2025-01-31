<?php

declare(strict_types=1);

namespace App;

use App\Backends\Common\ClientInterface as iClient;
use App\Libs\Config;
use App\Libs\ConfigFile;
use App\Libs\Exceptions\RuntimeException;
use App\Listeners\ProcessProfileEvent;
use Closure;
use DirectoryIterator;
use Symfony\Component\Console\Command\Command as BaseCommand;
use Symfony\Component\Console\Command\LockableTrait;
use Symfony\Component\Console\Completion\CompletionInput;
use Symfony\Component\Console\Completion\CompletionSuggestions;
use Symfony\Component\Console\Helper\Table;
use Symfony\Component\Console\Helper\TableSeparator;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Yaml\Yaml;

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
    public const array DISPLAY_OUTPUT = [
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
        if ($input->hasOption('debug') && $input->getOption('debug')) {
            $input->setOption('context', true);
            $input->setOption('trace', true);
            $input->setOption('verbose', true);
            if (function_exists('putenv')) {
                @putenv('SHELL_VERBOSITY=3');
            }
            $output->setVerbosity(OutputInterface::VERBOSITY_DEBUG);
        }

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

        if (false === class_exists('Xhgui\Profiler\Profiler') || false === extension_loaded('xhprof')) {
            throw new RuntimeException('The profiler is not available. Please install the xhprof extension.');
        }

        $profiler = new \Xhgui\Profiler\Profiler(Config::get('profiler.config', []));
        $profiler->enable(Config::get('profiler.flags', null));
        $status = $this->runCommand($input, $output);
        $data = $profiler->disable();

        if (empty($data)) {
            throw new RuntimeException('The profiler run was unsuccessful. No data was returned.');
        }

        $removeKeys = [
            'meta.SERVER.HTTP_USER_AGENT',
            'meta.SERVER.PHP_AUTH_USER',
            'meta.SERVER.REMOTE_USER',
            'meta.SERVER.UNIQUE_ID'
        ];

        $appVersion = getAppVersion();
        $inContainer = inContainer();

        $url = str_replace(':', '/', '/cli/' . $this->getName());
        $data['meta']['id'] = generateUUID();
        $data['meta']['url'] = $data['meta']['simple_url'] = $url;
        $data['meta']['get'] = $data['meta']['env'] = [];
        $data['meta']['SERVER'] = array_replace_recursive($data['meta']['SERVER'], [
            'REQUEST_METHOD' => 'CLI',
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

        $data = ag_delete($data, $removeKeys);

        queueEvent(ProcessProfileEvent::NAME, $data);

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
        $configFile = ConfigFile::open(Config::get('backends_file'), 'yaml');

        if (null === $configFile->get("{$name}.type", null)) {
            throw new RuntimeException(r("No backend named '{backend}' was found.", ['backend' => $name]));
        }

        $default = $configFile->get($name);
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

                        if (ag_exists($item, 'type') && 'bool' === ag($item, 'type', 'string') && is_bool($leaf)) {
                            $subItem[$key] = $leaf ? 'true' : 'false';
                        }
                    }

                    $list[] = $subItem;
                    $list[] = new TableSeparator();
                }

                if (!empty($list)) {
                    array_pop($list);
                    new Table($output)
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

        if (
            $input->mustSuggestOptionValuesFor('select-backends') ||
            $input->mustSuggestOptionValuesFor('select-backend') ||
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
