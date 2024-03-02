<?php

declare(strict_types=1);

namespace App\Commands\Config;

use App\Command;
use App\Libs\Attributes\Route\Cli;
use App\Libs\Config;
use App\Libs\Stream;
use Symfony\Component\Console\Completion\CompletionInput;
use Symfony\Component\Console\Completion\CompletionSuggestions;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Yaml\Yaml;
use Throwable;

/**
 * Class UnifyCommand
 *
 * UnifyCommand is a command that unifies the webhook tokens of backend types.
 *
 * @package Your\Namespace
 */
#[Cli(command: self::ROUTE)]
final class UnifyCommand extends Command
{
    public const ROUTE = 'config:unify';

    /**
     * Configure the command.
     */
    protected function configure(): void
    {
        $this->setName(self::ROUTE)
            ->setDescription('Unify backend type webhook tokens.')
            ->addOption('select-backends', 's', InputOption::VALUE_OPTIONAL, 'Select backends. comma , seperated.', '')
            ->addOption('config', 'c', InputOption::VALUE_REQUIRED, 'Use Alternative config file.')
            ->addArgument(
                'type',
                InputArgument::REQUIRED,
                sprintf(
                    'Backend type to unify. Expecting one of [%s]',
                    implode('|', array_keys(Config::get('supported', [])))
                ),
            )
            ->setHelp(
                r(
                    <<<HELP

                    This command is mainly intended for <notice>Plex</notice> multi server users.
                    You shouldn't use this command unless told by the team.

                    Due to <notice>Plex</notice> webhook limitation you cannot use multiple webhook tokens for one PlexPass account.
                    And as workaround we have to use one webhook token for all of your <notice>Plex</notice> backends.

                    This command will do the following.

                    3. Update backends unique identifier (uuid).
                    1. Change the selected backend's webhook tokens to be replica of each other.
                    2. Enable limit backend webhook requests to matching unique identifier.

                    To execute the command, you can do the following

                    {cmd} <cmd>{route}</cmd> -- <value>plex</value>

                    HELP,
                    [
                        'cmd' => trim(commandContext()),
                        'route' => self::ROUTE,
                    ]
                )
            );
    }

    /**
     * Run the command.
     *
     * @param InputInterface $input The input interface.
     * @param OutputInterface $output The output interface.
     *
     * @return int Returns the exit code.
     */
    protected function runCommand(InputInterface $input, OutputInterface $output): int
    {
        // -- Use Custom servers.yaml file.
        if (($config = $input->getOption('config'))) {
            try {
                $custom = true;
                Config::save('servers', Yaml::parseFile($this->checkCustomBackendsFile($config)));
            } catch (\App\Libs\Exceptions\RuntimeException $e) {
                $output->writeln(sprintf('<error>%s</error>', $e->getMessage()));
                return self::FAILURE;
            }
        } else {
            $custom = false;
            $config = Config::get('path') . '/config/servers.yaml';
            if (!file_exists($config)) {
                touch($config);
            }
        }

        $type = strtolower((string)$input->getArgument('type'));

        if (!array_key_exists($type, Config::get('supported', []))) {
            $message = r("<error>Invalid type was given. Expecting one of [{backends}] but got '{backend}' instead.", [
                'backends' => implode('|', array_keys(Config::get('supported', []))),
                'backend' => $type,
            ]);

            $output->writeln($message);
            return self::FAILURE;
        }

        $selectBackends = (string)$input->getOption('select-backends');

        $selected = explode(',', $selectBackends);
        $selected = array_map('trim', $selected);
        $isCustom = !empty($selectBackends) && count($selected) >= 1;

        $list = $keys = [];

        foreach (Config::get('servers', []) as $backendName => $backend) {
            if (ag($backend, 'type') !== $type) {
                $output->writeln(
                    sprintf(
                        '<comment>Ignoring \'%s\' backend, not of %s type. (type: %s).</comment>',
                        $backendName,
                        $type,
                        ag($backend, 'type')
                    ),
                    OutputInterface::VERBOSITY_DEBUG
                );
                continue;
            }

            if ($isCustom && !in_array($backendName, $selected, true)) {
                $output->writeln(
                    sprintf(
                        '<comment>Ignoring \'%s\' as requested by [-s, --select-backends] filter.</comment>',
                        $backendName
                    ),
                    OutputInterface::VERBOSITY_DEBUG
                );
                continue;
            }

            $backend['name'] = $backendName;
            $backend['ref'] = "servers.{$backendName}";

            $list[$backendName] = $backend;

            if (null === ($apiToken = ag($backend, 'webhook.token', null))) {
                try {
                    $apiToken = bin2hex(random_bytes(Config::get('webhook.tokenLength')));
                } catch (Throwable $e) {
                    $output->writeln(sprintf('<error>%s</error>', $e->getMessage()));
                    return self::FAILURE;
                }
            }

            $keys[$apiToken] = 1;
        }

        $count = count($list);

        if (0 === $count) {
            $message = sprintf(
                $isCustom ? '[-s, --select-backends] did not return any %s backends.' : 'No %s backends were found.',
                $type
            );
            $output->writeln(sprintf('<error>%s</error>', $message));
            return self::FAILURE;
        }

        if (1 === $count) {
            $output->writeln(sprintf('<info>We found only one %s backend, therefore, no need to unify.</info>', $type));
            return self::SUCCESS;
        }

        if (count($keys) <= 1) {
            $output->writeln(sprintf('<info>[%s] Webhook tokens are already unified.</info>', ucfirst($type)));
            return self::SUCCESS;
        }

        // -- check for server unique identifier before unifying.
        foreach ($list as $backendName => $backend) {
            $ref = ag($backend, 'ref');

            if (null !== Config::get("{$ref}.uuid", null)) {
                continue;
            }

            $client = makeBackend(Config::get($ref), $backendName);

            $uuid = $client->getContext()->backendId ?? $client->getIdentifier(true);

            if (empty($uuid)) {
                $output->writeln(
                    sprintf('<error>ERROR %s: does not have backend unique id set.</error>', $backendName)
                );
                $output->writeln('<comment>Please run this command to update backend info.</comment>');
                $output->writeln(sprintf(commandContext() . 'config:manage \'%s\' ', $backendName));
                return self::FAILURE;
            }

            Config::save("{$ref}.uuid", $uuid);
        }

        try {
            $apiToken = array_keys($keys ?? [])[0] ?? bin2hex(random_bytes(Config::get('webhook.tokenLength')));
        } catch (Throwable $e) {
            $output->writeln(sprintf('<error>%s</error>', $e->getMessage()));
            return self::FAILURE;
        }

        foreach ($list as $backend) {
            $ref = ag($backend, 'ref');
            Config::save("{$ref}.webhook.token", $apiToken);
            Config::save("{$ref}.webhook.match.uuid", true);
        }

        if (false === $custom) {
            copy($config, $config . '.bak');
        }

        $stream = new Stream($config, 'w');
        $stream->write(Yaml::dump(Config::get('servers', []), 8, 2));
        $stream->close();

        $output->writeln(
            sprintf('<comment>Unified the webhook token of %d %s backends.</comment>', count($list), $type)
        );
        $output->writeln(sprintf('<info>%s global webhook API key is: %s</info>', ucfirst($type), $apiToken));
        return self::SUCCESS;
    }

    /**
     * Completes the input with suggestions based on the "type" argument.
     *
     * @param CompletionInput $input The completion input object.
     * @param CompletionSuggestions $suggestions The completion suggestions object.
     */
    public function complete(CompletionInput $input, CompletionSuggestions $suggestions): void
    {
        parent::complete($input, $suggestions);

        if ($input->mustSuggestArgumentValuesFor('type')) {
            $currentValue = $input->getCompletionValue();

            $suggest = [];

            foreach (array_keys(Config::get('supported', [])) as $name) {
                if (empty($currentValue) || str_starts_with($name, $currentValue)) {
                    $suggest[] = $name;
                }
            }

            $suggestions->suggestValues($suggest);
        }
    }
}
