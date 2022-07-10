<?php

declare(strict_types=1);

namespace App\Commands\Backend\Library;

use App\Command;
use App\Commands\Config\EditCommand;
use App\Libs\Config;
use App\Libs\Routable;
use Exception;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Question\ConfirmationQuestion;
use Symfony\Component\Yaml\Yaml;

#[Routable(command: self::ROUTE)]
final class IgnoreCommand extends Command
{
    public const ROUTE = 'backend:library:ignore';

    protected function configure(): void
    {
        $this->setName(self::ROUTE)
            ->setDescription('Manage Backend ignored libraries.')
            ->addOption('config', 'c', InputOption::VALUE_REQUIRED, 'Use Alternative config file.')
            ->addArgument('backend', InputArgument::REQUIRED, 'Backend name.')
            ->setHelp(
                r(
                    <<<HELP

 This command help manage your ignored libraries list.
 This command require interaction to select the library that you want to ignore.

 -------
 <comment>[ FAQ ]</comment>
 -------

 <comment># I can't use interaction is there different way to ignore library?</comment>

 Yes, you can do it the manual way, First get list of your libraries ids, and to do so run the following command

 {cmd} {library_list} -- [<info>BACKEND_NAME</info>]

 You are mainly interested in the <info>Id</info> column, once you have list of your ids, you can run the following command
 to update the ignorelist. the [<comment>options.ignore</comment>] key accept comma seperated list of ids.

 {cmd} {backend_edit} --key '<comment>options.ignore</comment>' --set '<info>id1<comment>,</comment>id2<comment>,</comment>id3</info>' -- [<info>BACKEND_NAME</info>]

 You can also directly update the [<info>servers.yaml</info>] file found in [<comment>{configPath}</comment>],
 although it's not recommended to manually edit the file.


 HELP,
                    [
                        'cmd' => trim(commandContext()),
                        'library_list' => ListCommand::ROUTE,
                        'backend_edit' => EditCommand::ROUTE,
                        'configPath' => Config::get('path') . '/config',
                    ]
                )
            );
    }

    /**
     * @throws Exception
     */
    protected function runCommand(InputInterface $input, OutputInterface $output, null|array $rerun = null): int
    {
        // -- Use Custom servers.yaml file.
        if (($config = $input->getOption('config'))) {
            try {
                $custom = true;
                $backends = Yaml::parseFile($this->checkCustomBackendsFile($config));
            } catch (\RuntimeException $e) {
                $output->writeln(sprintf('<error>%s</error>', $e->getMessage()));
                return self::FAILURE;
            }
        } else {
            $custom = false;
            $config = Config::get('path') . '/config/servers.yaml';
            $backends = Config::get('servers', []);
        }

        $name = $input->getArgument('backend');

        if (null === ag($backends, $name)) {
            $output->writeln(r('<error>ERROR: Backend [{backend}] not found.</error>', ['backend' => $name]));
            return self::FAILURE;
        }

        $backend = makeBackend(ag($backends, $name), $name);

        $list = $backend->listLibraries();

        if (empty($list)) {
            $output->writeln(
                r('<error>ERROR: Could not find any library for [{backend}].</error>', ['backend' => $name])
            );
            return self::FAILURE;
        }

        $helper = $this->getHelper('question');

        $newList = [];

        foreach ($list as $library) {
            $yes = false;

            if (false === ag($library, 'supported') || true === ag($library, 'ignored')) {
                $yes = true;
            }

            $text = r('Ignore [<info>{library}</info>] - [Type: <comment>{type}</comment>] ? <comment>%s</comment>', [
                'library' => ag($library, 'title'),
                'type' => ag($library, 'type'),
            ]);

            $question = new ConfirmationQuestion(
                sprintf(
                    $text . PHP_EOL . '> ',
                    '[Y|N] [Default: ' . ($yes ? 'Yes' : 'No') . ']',
                ),
                $yes
            );

            if (true === (bool)$helper->ask($input, $output, $question)) {
                $newList[ag($library, 'id')] = ag($library, 'title');
            }
        }

        $confirmText = implode(PHP_EOL, array_map(fn($val) => '[<info>✓</info>] ' . $val, $newList));

        if (empty($confirmText)) {
            $confirmText = '[<comment>-</comment>] Non selected';
        }

        $text = r(
            <<<TEXT
Confirm Ignored list selection <comment>[Y|N] [Default: No]</comment>
-------------------
{list}
-------------------
TEXT,
            [
                'list' => $confirmText,
            ]
        );

        $question = new ConfirmationQuestion($text . PHP_EOL . '> ', false);

        if (false === (bool)$helper->ask($input, $output, $question)) {
            $output->writeln('<comment>Nothing updated. Confirmation failed.</comment>');
            return self::FAILURE;
        }

        $ignored = implode(',', array_keys($newList));

        if (empty($ignored)) {
            $backends = ag_delete($backends, "{$name}.options.ignore");
            if (count(ag($backends, "{$name}.options", [])) < 1) {
                $backends = ag_delete($backends, "{$name}.options");
            }
            $backends = ag_delete($backends, "{$name}.options.ignore");
        } else {
            $backends = ag_set($backends, "{$name}.options.ignore", $ignored);
        }

        if (false === $custom) {
            copy($config, $config . '.bak');
        }

        file_put_contents($config, Yaml::dump($backends, 8, 2));

        return self::SUCCESS;
    }
}
