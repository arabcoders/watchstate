<?php

declare(strict_types=1);

return [
    'config:dump' => App\Commands\Config\DumpCommand::class,
    'config:generate' => App\Commands\Config\GenerateCommand::class,
    'config:php' => App\Commands\Config\PHPCommand::class,
    'state:import' => App\Commands\State\ImportCommand::class,
    'state:export' => App\Commands\State\ExportCommand::class,
    'storage:maintenance' => App\Commands\Storage\MaintenanceCommand::class,
    'storage:migrations' => App\Commands\Storage\MigrationsCommand::class,
    'storage:make' => App\Commands\Storage\MakeCommand::class,
    'scheduler:list' => App\Commands\Scheduler\Lists::class,
    'scheduler:run' => App\Commands\Scheduler\Run::class,
    'scheduler:closure' => App\Commands\Scheduler\RunClosure::class,
    'webhooks:queued' => App\Commands\State\QueueCommand::class,
    'servers:list' => App\Commands\Servers\ListCommand::class,
    'servers:webhook' => App\Commands\Servers\WebhookCommand::class,
];
