<?php

declare(strict_types=1);

use App\Commands\Config\DumpCommand;
use App\Commands\Config\GenerateCommand;
use App\Commands\Config\PHPCommand;
use App\Commands\Scheduler\Lists;
use App\Commands\Scheduler\Run;
use App\Commands\Scheduler\RunClosure;
use App\Commands\State\ExportCommand;
use App\Commands\State\ImportCommand;
use App\Commands\State\QueueCommand;
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
    'scheduler:list' => Lists::class,
    'scheduler:run' => Run::class,
    'scheduler:closure' => RunClosure::class,
    'webhooks:queued' => QueueCommand::class,
];
