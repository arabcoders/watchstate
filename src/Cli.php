<?php

declare(strict_types=1);

namespace App;

use App\Libs\Config;
use App\Libs\Extends\PSRContainer;
use Composer\InstalledVersions;
use Symfony\Component\Console\Application;
use Symfony\Component\Console\Input\InputDefinition;
use Symfony\Component\Console\Input\InputOption;

class Cli extends Application
{
    public function __construct(protected PSRContainer $container)
    {
        parent::__construct(self::getAppName(), getAppVersion());
    }

    public static function getAppName(): string
    {
        return Config::get('name');
    }

    protected function getDefaultInputDefinition(): InputDefinition
    {
        $definition = parent::getDefaultInputDefinition();

        if (InstalledVersions::isInstalled('perftools/php-profiler')) {
            $definition->addOption(
                new InputOption('profile', null, InputOption::VALUE_NONE, 'Run profiler on command execution.')
            );
        }

        $definition->addOption(
            new InputOption('context', null, InputOption::VALUE_NEGATABLE, 'Add context to output messages.')
        );

        $definition->addOption(new InputOption('trace', null, InputOption::VALUE_NONE, 'Enable tracing mode.'));

        $definition->addOption(
            new InputOption(
                'output', 'o', InputOption::VALUE_REQUIRED,
                sprintf('Output mode. Can be [%s].', implode(', ', Command::DISPLAY_OUTPUT)),
                Command::DISPLAY_OUTPUT[0]
            )
        );

        return $definition;
    }
}
