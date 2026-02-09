<?php

declare(strict_types=1);

namespace App\Commands\Events;

use App\Command;
use App\Libs\Attributes\Route\Cli;
use Psr\EventDispatcher\EventDispatcherInterface as iDispatcher;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
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

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $mode = $input->getOption('output');
        $keys = [];

        assert($this->dispatcher instanceof EventDispatcher, 'Expected EventDispatcher for listeners list.');
        foreach ($this->dispatcher->getListeners() as $key => $val) {
            $listeners = [];

            foreach ($val as $listener) {
                $listeners[] = get_debug_type($listener);
            }

            $keys[$key] = implode(', ', $listeners);
        }

        if ('table' === $mode) {
            $list = [];

            foreach ($keys as $key => $val) {
                $list[] = ['Event' => $key, 'value' => $val];
            }

            $keys = $list;
        }

        $this->displayContent($keys, $output, $mode);

        return self::SUCCESS;
    }
}
