<?php

declare(strict_types=1);

namespace App\Commands\Events;

use App\Command;
use App\Libs\Attributes\Route\Cli;
use Psr\EventDispatcher\EventDispatcherInterface as iDispatcher;
use Symfony\Component\Console\Formatter\OutputFormatter;
use Symfony\Component\Console\Input\InputInterface as iInput;
use Symfony\Component\Console\Output\OutputInterface as iOutput;
use Symfony\Component\EventDispatcher\EventDispatcher;

#[Cli(command: self::ROUTE)]
final class ListenersCommand extends Command
{
    public const string ROUTE = 'events:listeners';

    public function __construct(
        private readonly iDispatcher $dispatcher,
    ) {
        parent::__construct(null);
    }

    protected function configure(): void
    {
        $this->setName(self::ROUTE)->setDescription('Show registered events Listeners.');
    }

    protected function runCommand(iInput $input, iOutput $output): int
    {
        $keys = [];

        assert($this->dispatcher instanceof EventDispatcher, 'Expected EventDispatcher for listeners list.');
        foreach ($this->dispatcher->getListeners() as $key => $val) {
            $listeners = [];

            foreach ($val as $listener) {
                $listeners[] = get_debug_type($listener);
            }

            $keys[$key] = implode(', ', $listeners);
        }

        if ((bool) $input->getOption('json')) {
            $this->displayContent($keys, $output, true);
            return self::SUCCESS;
        }

        foreach ($keys as $key => $listeners) {
            $output->writeln(r('<info>Event: {event}</info>', ['event' => $key]));
            $output->writeln(OutputFormatter::escape('  Listeners: ' . $listeners));
        }

        return self::SUCCESS;
    }
}
