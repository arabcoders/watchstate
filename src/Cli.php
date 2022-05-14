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
                new InputOption('profile', null, InputOption::VALUE_NONE, 'Profile command.')
            );
        }

        return $definition;
    }
}
