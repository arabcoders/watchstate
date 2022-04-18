<?php

declare(strict_types=1);

return [
    // config:
    'config:php' => App\Commands\Config\PHPCommand::class,
    'config:env' => App\Commands\Config\EnvCommand::class,
    // -- state:
    'state:import' => App\Commands\State\ImportCommand::class,
    'state:export' => App\Commands\State\ExportCommand::class,
    'state:push' => App\Commands\State\PushCommand::class,
    'state:cache' => App\Commands\State\CacheCommand::class,
    // -- storage:
    'storage:maintenance' => App\Commands\Storage\MaintenanceCommand::class,
    'storage:migrations' => App\Commands\Storage\MigrationsCommand::class,
    'storage:make' => App\Commands\Storage\MakeCommand::class,
    // -- scheduler:
    'scheduler:list' => App\Commands\Scheduler\ListCommand::class,
    'scheduler:run' => App\Commands\Scheduler\RunCommand::class,
    'scheduler:closure' => App\Commands\Scheduler\RunClosureCommand::class,
    // -- server:
    'servers:list' => App\Commands\Servers\ListCommand::class,
    'servers:manage' => App\Commands\Servers\ManageCommand::class,
    'servers:unify' => App\Commands\Servers\UnifyCommand::class,
    'servers:view' => App\Commands\Servers\ViewCommand::class,
    'servers:remote' => App\Commands\Servers\RemoteCommand::class,
    'servers:edit' => App\Commands\Servers\EditCommand::class,
    // -- db:
    'db:list' => App\Commands\Database\ListCommand::class,
];
