<?php

declare(strict_types=1);

namespace App\Libs\Scheduler;

final class Scheduler
{
    /**
     * @var array<Task>
     */
    private array $queuedTasks = [];

    /**
     * @return array<string, Task>
     */
    private array $executedTasks = [];

    /**
     * Queue a task for execution in the correct queue.
     */
    public function queueTask(Task $task): void
    {
        $this->queuedTasks[] = $task;
    }

    /**
     * Prioritise tasks in background.
     *
     * @return array<Task>
     */
    private function getQueuedTasks(): array
    {
        $background = [];
        $foreground = [];

        foreach ($this->queuedTasks as $task) {
            if ($task->canRunInBackground()) {
                $background[] = $task;
            } else {
                $foreground[] = $task;
            }
        }

        return array_merge($background, $foreground);
    }

    /**
     * Run the scheduler.
     *
     * @param \DateTimeInterface $runTime Run at specific moment.
     * @return array  Executed tasks
     */
    public function run(\DateTimeInterface $runTime): array
    {
        foreach ($this->getQueuedTasks() as $task) {
            if (!$task->isDue($runTime)) {
                continue;
            }
            $task->run();
            $this->executedTasks[$task->getName()] = $task;
        }

        return $this->getExecutedTasks();
    }

    /**
     * @return array<Task>
     * @psalm-return array<string,Task>
     */
    public function getExecutedTasks(): array
    {
        return $this->executedTasks;
    }
}
