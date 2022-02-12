<?php

declare(strict_types=1);

namespace App\Commands\Scheduler;

use App\Command;
use App\Libs\Config;
use App\Libs\Scheduler\Task;
use App\Libs\Scheduler\TaskTimer;
use Closure;
use DateTimeInterface;
use DateTimeZone;
use Exception;
use RuntimeException;
use Symfony\Component\Console\Helper\Table;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

final class Lists extends Command
{
    protected function configure(): void
    {
        $this->setName('scheduler:list')
            ->addOption('timezone', 't', InputOption::VALUE_REQUIRED, 'Set Timezone.', Config::get('tz', 'UTC'))
            ->setDescription('List Scheduled Tasks.');
    }

    /**
     * @throws Exception
     */
    protected function runCommand(InputInterface $input, OutputInterface $output): int
    {
        $list = [];

        $table = new Table($output);
        $table->setHeaders(
            [
                'Name',
                'Command',
                'Run As',
                'Run At',
                'In Background'
            ]
        );

        foreach (Run::getTasks() as $task) {
            $task = Run::fixTask($task);

            $timer = $task[Task::RUN_AT] ?? TaskTimer::everyMinute(5);
            $task[Task::RUN_AT] = $timer->getNextRunDate('now');
            $task[Task::RUN_IN_FOREGROUND] = (bool)($task[Task::RUN_IN_FOREGROUND] ?? false);

            if (false === $task[Task::USE_CLOSURE_AS_COMMAND] && ($task[Task::COMMAND] instanceof Closure)) {
                $task['Type'] = 'PHP Sub Process';
                $task[Task::COMMAND] = 'Closure';
            } else {
                if (($task[Task::COMMAND] instanceof Closure)) {
                    $task[Task::COMMAND] = RunClosure::runClosure($task[Task::COMMAND], $task[Task::CONFIG]);
                }

                if (!is_string($task[Task::COMMAND])) {
                    throw new RuntimeException(
                        sprintf('Task \'%s\' Command did not evaluated to a string.', $task['name'])
                    );
                }

                if (str_starts_with($task[Task::COMMAND], '@')) {
                    $task[Task::COMMAND] = substr($task[Task::COMMAND], 1);
                    $task['Type'] = 'App Command';
                } else {
                    $task['Type'] = 'Raw Shell';
                }
            }

            $list[] = [
                $task[Task::NAME],
                $task[Task::COMMAND],
                $task['Type'],
                $task[Task::ENABLED] ? $task[Task::RUN_AT]->setTimezone(
                    new DateTimeZone($input->getOption('timezone'))
                )->format(
                    DateTimeInterface::ATOM
                ) : 'Disabled via config',
                $task[Task::RUN_IN_FOREGROUND] ? 'No' : 'Yes',
            ];
        }

        $table->setRows($list);

        $table->render();

        return self::SUCCESS;
    }
}
