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
    /**
     * Constructs a new instance of the class.
     *
     * @param PSRContainer $container The dependency injection container.
     */
    public function __construct(protected PSRContainer $container)
    {
        parent::__construct(self::getAppName(), getAppVersion());
    }

    /**
     * Get the name of the application from the configuration.
     *
     * @return string The name of the application.
     */
    public static function getAppName(): string
    {
        return Config::get('name');
    }

    /**
     * Get the default input definition for the command.
     *
     * @return InputDefinition The default input definition.
     */
    protected function getDefaultInputDefinition(): InputDefinition
    {
        $definition = parent::getDefaultInputDefinition();

        if (InstalledVersions::isInstalled('perftools/php-profiler')) {
            $definition->addOption(
                new InputOption('profile', null, InputOption::VALUE_NONE, 'Run profiler on command execution.')
            );
        }

        $definition->addOption(
            new InputOption(
                'context',
                null,
                InputOption::VALUE_NEGATABLE,
                'Add context to output messages. <comment>Not all commands support this option.</comment>'
            )
        );

        $definition->addOption(
            new InputOption(
                'trace',
                null,
                InputOption::VALUE_NONE,
                'Enable tracing mode. <comment>Not all commands support this option.</comment>'
            )
        );

        $definition->addOption(
            new InputOption(
                'output', 'o', InputOption::VALUE_REQUIRED,
                sprintf(
                    'Change output display mode. Can be [%s]. <comment>Not all commands support this option.</comment>',
                    '<info>' . implode('</info>,<info> ', Command::DISPLAY_OUTPUT) . '</info>'
                ),
                Command::DISPLAY_OUTPUT[0]
            )
        );

        $definition->addOption(
            new InputOption(
                'debug',
                null,
                InputOption::VALUE_NONE,
                'Turn on the <comment>-vvv --context --trace</comment> flags.'
            )
        );

        return $definition;
    }
}
