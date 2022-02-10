<?php

declare(strict_types=1);

use App\Commands\Config\DumpCommand;
use App\Commands\Config\GenerateCommand;
use App\Commands\Config\PHPCommand;
use App\Commands\State\ExportCommand;
use App\Commands\State\ImportCommand;
use App\Commands\Storage\MaintenanceCommand;
use App\Commands\Storage\MakeCommand;
use App\Commands\Storage\MigrationsCommand;

return [
    'config:dump' => DumpCommand::class,
    'config:generate' => GenerateCommand::class,
    'config:php' => PHPCommand::class,
    'state:import' => ImportCommand::class,
    'state:export' => ExportCommand::class,
    'storage:maintenance' => MaintenanceCommand::class,
    'storage:migrations' => MigrationsCommand::class,
    'storage:make' => MakeCommand::class,
];
