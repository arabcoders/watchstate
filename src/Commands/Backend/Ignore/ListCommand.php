<?php

declare(strict_types=1);

namespace App\Commands\Backend\Ignore;

use App\Command;
use App\Libs\Config;
use App\Libs\Entity\StateInterface as iFace;
use App\Libs\Guid;
use Symfony\Component\Console\Completion\CompletionInput;
use Symfony\Component\Console\Completion\CompletionSuggestions;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

final class ListCommand extends Command
{
    protected function configure(): void
    {
        $cmdContext = trim(commandContext());

        $this->setName('backend:ignore:list')
            ->addOption('type', null, InputOption::VALUE_REQUIRED, 'Filter based on type.')
            ->addOption('backend', null, InputOption::VALUE_REQUIRED, 'Filter based on backend.')
            ->addOption('db', null, InputOption::VALUE_REQUIRED, 'Filter based on db.')
            ->addOption('id', null, InputOption::VALUE_REQUIRED, 'Filter based on id.')
            ->setDescription('List Ignored external ids.')
            ->setHelp(
                <<<HELP

This command display list of ignored external ids.

You can filter the results by using one or more of the provided options like <info>--type</info>, <info>--backend</info>, <info>--db</info>

For example, To list all ids that are being ignored for specific <info>backend</info>, You can do something like

{$cmdContext} backend:ignore:list --backend plex_home

You can append more filters to narrow down the list. For example, to filter on both <info>backend</info> and <info>db</info>:

{$cmdContext} backend:ignore:list --backend plex_home --db tvdb

HELP

            );
    }

    protected function runCommand(InputInterface $input, OutputInterface $output): int
    {
        $list = [];

        $fBackend = $input->getOption('backend');
        $fType = $input->getOption('type');
        $fDb = $input->getOption('db');
        $fId = $input->getOption('id');

        $ids = Config::get('ignore', []);

        foreach ($ids as $guid => $date) {
            $urlParts = parse_url($guid);

            $backend = ag($urlParts, 'host');
            $type = ag($urlParts, 'scheme');
            $db = ag($urlParts, 'user');
            $id = ag($urlParts, 'pass');

            if (null !== $fBackend && $backend !== $fBackend) {
                continue;
            }

            if (null !== $fType && $type !== $fType) {
                continue;
            }

            if (null !== $fDb && $db !== $fDb) {
                continue;
            }

            if (null !== $fId && $id !== $fId) {
                continue;
            }

            $list[] = [
                'backend' => $backend,
                'type' => $type,
                'db' => $db,
                'id' => $id,
                'created' => makeDate($date),
            ];
        }

        if (empty($list)) {
            $hasIds = count($ids) >= 1;
            $output->writeln(
                $hasIds ? '<comment>Filters did not return any results.</comment>' : '<info>Ignore list is empty.</info>'
            );
            if ($hasIds) {
                return self::FAILURE;
            }
        }

        $this->displayContent($list, $output, $input->getOption('output'));

        return self::SUCCESS;
    }

    public function complete(CompletionInput $input, CompletionSuggestions $suggestions): void
    {
        if ($input->mustSuggestOptionValuesFor('backend')) {
            $currentValue = $input->getCompletionValue();

            $suggest = [];

            foreach (array_keys(Config::get('servers', [])) as $name) {
                if (empty($currentValue) || str_starts_with($name, $currentValue)) {
                    $suggest[] = $name;
                }
            }

            $suggestions->suggestValues($suggest);
        }
        if ($input->mustSuggestOptionValuesFor('type')) {
            $currentValue = $input->getCompletionValue();

            $suggest = [];

            foreach (iFace::TYPES_LIST as $name) {
                if (empty($currentValue) || str_starts_with($name, $currentValue)) {
                    $suggest[] = $name;
                }
            }

            $suggestions->suggestValues($suggest);
        }
        if ($input->mustSuggestOptionValuesFor('db')) {
            $currentValue = $input->getCompletionValue();

            $suggest = [];

            foreach (array_keys(Guid::getSupported(includeVirtual: false)) as $name) {
                $name = after($name, 'guid_');
                if (empty($currentValue) || str_starts_with($name, $currentValue)) {
                    $suggest[] = $name;
                }
            }

            $suggestions->suggestValues($suggest);
        }
    }
}
