<?php

declare(strict_types=1);

namespace App\Commands\Servers;

use App\Command;
use App\Libs\Config;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Question\ChoiceQuestion;
use Symfony\Component\Console\Question\ConfirmationQuestion;
use Symfony\Component\Console\Question\Question;
use Symfony\Component\Yaml\Yaml;

final class AddCommand extends Command
{
    protected function configure(): void
    {
        $this->setName('servers:add')
            ->setDescription('Add Server.')
            ->addOption('redirect-logger', 'r', InputOption::VALUE_NONE, 'Redirect logger to stdout.')
            ->addOption(
                'type',
                null,
                InputOption::VALUE_REQUIRED,
                sprintf(
                    'Change server type. Expected value is one of [%s]',
                    implode('|', array_keys(Config::get('supported', [])))
                )
            )
            ->addOption(
                'url',
                null,
                InputOption::VALUE_REQUIRED,
                'Change server url.'
            )
            ->addOption(
                'token',
                null,
                InputOption::VALUE_REQUIRED,
                'Change server API key.'
            )
            ->addOption('use-config', null, InputOption::VALUE_REQUIRED, 'Use different servers.yaml.')
            ->addOption('no-backup', null, InputOption::VALUE_NONE, 'Do not create copy servers.yaml before editing.')
            ->addArgument('name', InputArgument::REQUIRED, 'Server name');
    }

    protected function runCommand(InputInterface $input, OutputInterface $output): int
    {
        // -- Use Custom servers.yaml file.
        if (($config = $input->getOption('use-config'))) {
            if (!is_string($config) || !is_file($config) || !is_readable($config)) {
                $output->writeln('<error>Unable to read data given config.</error>');
            }
            Config::save('servers', Yaml::parseFile($config));
        } else {
            $config = Config::get('path') . '/config/servers.yaml';
        }

        $name = $input->getArgument('name');
        $name = str_replace(['-', ' '], '_', $name);
        $ref = "servers.{$name}";

        if (null !== Config::get("{$ref}.type", null)) {
            $output->writeln(sprintf('<error>Server name \'%s\' already in use .</error>', $name));
            return self::FAILURE;
        }

        Config::save($ref, []);

        // -- $type
        if ($input->getOption('type')) {
            if (!array_key_exists($input->getOption('type'), Config::get('supported', []))) {
                $output->writeln(
                    sprintf(
                        '<error>Unexpected value for --type, was expecting one of [%s] but got \'%s\' instead.',
                        implode('|', array_keys(Config::get('supported', []))),
                        $input->getOption('type')
                    )
                );
                return self::FAILURE;
            }
            Config::save("{$ref}.type", $input->getOption('type'));
        } else {
            if ($input->getOption('no-interaction')) {
                $output->writeln('<error>No type was set and no interaction was requested.</error>');
                return self::FAILURE;
            }

            $helper = $this->getHelper('question');

            $question = new ChoiceQuestion('Select Server Type', array_keys(Config::get('supported')), null);
            $question->setErrorMessage('Selected Number %s is invalid.');

            $type = $helper->ask($input, $output, $question);
            Config::save("{$ref}.type", $type);
        }

        // -- $ref.url
        if ($input->getOption('url')) {
            if (!filter_var($input->getOption('url'), FILTER_VALIDATE_URL)) {
                $output->writeln(sprintf('<error>Invalid --url value \'%s\' was given.', $input->getOption('url')));
                return self::FAILURE;
            }
            Config::save("{$ref}.url", $input->getOption('url'));
        } else {
            if ($input->getOption('no-interaction')) {
                $output->writeln('<error>No url was set and no interaction was requested.</error>');
                return self::FAILURE;
            }

            $helper = $this->getHelper('question');
            $question = new Question(
                sprintf(
                    'Please enter %s Server URL:' . PHP_EOL,
                    ucfirst(Config::get("{$ref}.type"))
                ),
                null
            );

            $question->setValidator(function ($answer) {
                if (!filter_var($answer, FILTER_VALIDATE_URL)) {
                    throw new \RuntimeException('Invalid url was given.');
                }
                return $answer;
            });

            $url = $helper->ask($input, $output, $question);
            Config::save("{$ref}.url", $url);
        }

        // -- $ref.token
        if ($input->getOption('token')) {
            if (!is_string($input->getOption('token')) && !is_int($input->getOption('token'))) {
                $output->writeln(
                    sprintf(
                        '<error>Expecting --token value to be string or integer. but got \'%s\' instead.',
                        get_debug_type($input->getOption('token'))
                    )
                );
                return self::FAILURE;
            }
            Config::save("{$ref}.token", $input->getOption('token'));
        } else {
            if ($input->getOption('no-interaction')) {
                $output->writeln('<error>No token was set and no interaction was requested.</error>');
                return self::FAILURE;
            }

            $helper = $this->getHelper('question');
            $question = new Question(
                sprintf(
                    'Please enter %s Server apikey/token:' . PHP_EOL,
                    ucfirst(Config::get("{$ref}.type"))
                ),
                null
            );

            $question->setValidator(function ($answer) {
                if (empty($answer)) {
                    throw new \RuntimeException('Invalid api/token was given.');
                }
                return $answer;
            });

            $token = $helper->ask($input, $output, $question);
            Config::save("{$ref}.token", $token);
        }

        $output->writeln('');
        $output->writeln(Yaml::dump([$name => Config::get($ref)], 8, 2));
        $output->writeln('');

        $helper = $this->getHelper('question');
        $question = new ConfirmationQuestion('Is the displayed info correct? [y|n]: ', true);

        if (true === $helper->ask($input, $output, $question)) {
            if (!$input->getOption('no-backup') && is_writable(dirname($config))) {
                copy($config, $config . '.bak');
            }

            file_put_contents($config, Yaml::dump(Config::get('servers', []), 8, 2));

            return self::SUCCESS;
        }

        return self::FAILURE;
    }
}
