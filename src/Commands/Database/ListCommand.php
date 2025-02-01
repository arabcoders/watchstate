<?php

declare(strict_types=1);

namespace App\Commands\Database;

use App\API\History\Index;
use App\Command;
use App\Libs\Attributes\DI\Inject;
use App\Libs\Attributes\Route\Cli;
use App\Libs\Config;
use App\Libs\Container;
use App\Libs\Entity\StateInterface as iState;
use App\Libs\Enums\Http\Status;
use App\Libs\Exceptions\RuntimeException;
use App\Libs\Guid;
use App\Libs\Mappers\ExtendedImportInterface as iEImport;
use App\Libs\Mappers\Import\DirectMapper;
use Psr\Log\LoggerInterface as iLogger;
use Symfony\Component\Console\Completion\CompletionInput;
use Symfony\Component\Console\Completion\CompletionSuggestions;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Question\ConfirmationQuestion;

/**
 * Class ListCommand
 *
 * This class act as frontend for the state table, it allows the user to see and manipulate view of state table.
 */
#[Cli(command: self::ROUTE)]
final class ListCommand extends Command
{
    public const string ROUTE = 'db:list';

    /**
     * Class constructor.
     *
     * @param DirectMapper $mapper The direct mapper object.
     *
     * @return void
     */
    public function __construct(#[Inject(DirectMapper::class)] private iEImport $mapper, private iLogger $logger)
    {
        parent::__construct();
    }

    /**
     * Configure the command.
     */
    protected function configure(): void
    {
        $list = [];

        foreach (array_keys(Guid::getSupported()) as $guid) {
            $guid = afterLast($guid, 'guid_');
            $list[] = '<value>' . $guid . '</value>';
        }

        $list = implode(', ', $list);

        $this->setName(self::ROUTE)
            ->addOption('user', 'u', InputOption::VALUE_REQUIRED, 'Display this user history.')
            ->addOption('limit', 'l', InputOption::VALUE_REQUIRED, 'Limit results to this number', 20)
            ->addOption(
                'via',
                null,
                InputOption::VALUE_REQUIRED,
                'Limit results to this specified backend. This filter is not reliable. and changes based on last backend query.'
            )
            ->addOption(
                'type',
                null,
                InputOption::VALUE_REQUIRED,
                'Limit results to this specified type can be [movie or episode].'
            )
            ->addOption('title', null, InputOption::VALUE_REQUIRED, 'Limit results to this specified title.')
            ->addOption('subtitle', null, InputOption::VALUE_REQUIRED, 'Limit results to this specified content title.')
            ->addOption('path', null, InputOption::VALUE_REQUIRED, 'Show results that contains this file path.')
            ->addOption('season', null, InputOption::VALUE_REQUIRED, 'Select season number.')
            ->addOption('episode', null, InputOption::VALUE_REQUIRED, 'Select episode number.')
            ->addOption('year', null, InputOption::VALUE_REQUIRED, 'Select year.')
            ->addOption('id', null, InputOption::VALUE_REQUIRED, 'Select db record number.')
            ->addOption(
                'sort',
                null,
                InputOption::VALUE_REQUIRED,
                'Set sort by columns. [Example: <flag>--sort</flag> <value>season:asc</value>].',
            )
            ->addOption(
                'guid',
                null,
                InputOption::VALUE_REQUIRED,
                'Search <notice>item</notice> external db ids. [Format: <value>db://id</value>].'
            )
            ->addOption(
                'parent',
                null,
                InputOption::VALUE_REQUIRED,
                'Search <notice>parent</notice> external db ids. [Format: <value>db://id</value>].'
            )
            ->addOption('key', null, InputOption::VALUE_REQUIRED, 'For <notice>JSON Fields</notice> key selection.')
            ->addOption('value', null, InputOption::VALUE_REQUIRED, 'For <notice>JSON Fields</notice> value selection.')
            ->addOption(
                'metadata',
                null,
                InputOption::VALUE_NONE,
                'Search in (<notice>metadata</notice>) provided by backends JSON field. Expects [<flag>--key</flag>, <flag>--value</flag>] flags.'
            )
            ->addOption(
                'extra',
                null,
                InputOption::VALUE_NONE,
                'Search in (<notice>extra</notice>) info by backends JSON field. Expects [<flag>--key</flag>, <flag>--value</flag>] flags.'
            )
            ->addOption(
                'exact',
                null,
                InputOption::VALUE_NONE,
                'Use <notice>equal</notice> check instead of <notice>LIKE</notice> for JSON field query.'
            )
            ->addOption(
                'mark-as',
                'm',
                InputOption::VALUE_REQUIRED,
                'Change items play state. Expects [<value>played</value>, <value>unplayed</value>] as value.'
            )
            ->setDescription('List Database entries.')
            ->setHelp(
                r(
                    <<<HELP

                    This command show your <notice>current</notice> stored play state.
                    This command is powerful tool to explore your database and the metadata gathered
                    about your media files. Please do read the options it's just too many to list here.

                    -------------------
                    <notice>[ Expected Values ]</notice>
                    -------------------

                    <flag>guid</flag>, <flag>parent</flag> expects the format to be [<value>db</value>://<value>id</value>]. Where the db refers to [{dbs_list}].

                    -------
                    <notice>[ FAQ ]</notice>
                    -------

                    <question># How to search JSON fields?</question>

                    You can search JSON fields [<notice>metadata</notice>, <notice>extra</notice>] by using the corresponding flags.
                    [<flag>--metadata</flag>, <flag>--extra</flag>] Searching JSON fields require the use of [<flag>--key</flag>] and [<flag>--value</flag>] flags as well.
                    Unlike regular table fields JSON fields does not have fixed schema. You can alter the search mode by using [<flag>--exact</flag>] flag
                    that will switch the search mode from loose to strict match.

                    For example, To search for item that match backend id, you would run the following:

                    {cmd} <cmd>{route}</cmd> <flag>--key</flag> '<value>backend_name</value>.id' <flag>--value</flag> '<value>backend_item_id</value>' <flag>--metadata</flag>

                    <question># How to mark items as played/unplayed?</question>

                    First Use filters to narrow down the list. then add the [<flag>-m</flag>, <flag>--mark-as</flag>] flag with one value of [<value>played</value>, <value>unplayed</value>].

                    Example, to mark a show that has id of [<value>tvdb://269586</value>], you would do something like.

                    {cmd} <cmd>{route}</cmd> <flag>--parent</flag> <value>tvdb://269586</value> <flag>--mark-as</flag> <value>played</value>

                    This flag require <notice>interaction</notice> to work. to bypass the check use <flag>[-n, --no-interaction]</flag> flag.

                    HELP,
                    [
                        'cmd' => trim(commandContext()),
                        'route' => self::ROUTE,
                        'dbs_list' => $list,
                    ]
                )
            );
    }

    /**
     * Runs a command and returns the number of rows affected.
     *
     * @param InputInterface $input The input object.
     * @param OutputInterface $output The output object.
     *
     * @return int The number of rows affected.
     */
    protected function runCommand(InputInterface $input, OutputInterface $output): int
    {
        $limit = (int)$input->getOption('limit');

        $params = [
            'perpage' => $limit <= 0 ? 20 : $limit,
        ];

        if ($input->getOption('id')) {
            $params['id'] = $input->getOption('id');
        }

        if ($input->getOption('via')) {
            $params['via'] = $input->getOption('via');
        }

        if ($input->getOption('year')) {
            $params['year'] = $input->getOption('year');
        }

        if ($input->getOption('type')) {
            $params['type'] = match ($input->getOption('type')) {
                iState::TYPE_MOVIE => iState::TYPE_MOVIE,
                default => iState::TYPE_EPISODE,
            };
        }

        if ($input->getOption('title')) {
            $params['title'] = $input->getOption('title');
        }

        if ($input->getOption('subtitle')) {
            $params['subtitle'] = $input->getOption('subtitle');
        }

        if (null !== $input->getOption('season')) {
            $params['season'] = $input->getOption('season');
        }

        if (null !== $input->getOption('episode')) {
            $params['episode'] = $input->getOption('episode');
        }

        if (null !== ($parent = $input->getOption('parent'))) {
            $d = Guid::fromArray(['guid_' . before($parent, '://') => after($parent, '://')]);
            $parent = array_keys($d->getAll())[0] ?? null;

            if (null === $parent) {
                $output->writeln(
                    '<error>ERROR:</error> Invalid value for [<flag>--parent</flag>] expected value format is [<value>db://id</value>].'
                );
                return self::INVALID;
            }

            $params['parent'] = array_values($d->getAll())[0];
        }

        if (null !== ($guid = $input->getOption('guid'))) {
            $d = Guid::fromArray(['guid_' . before($guid, '://') => after($guid, '://')]);
            $guid = array_keys($d->getAll())[0] ?? null;

            if (null === $guid) {
                $output->writeln(
                    '<error>ERROR:</error> Invalid value for [<flag>--guid</flag>] expected value format is [<value>db://id</value>]'
                );
                return self::INVALID;
            }

            $params['guid'] = array_values($d->getAll())[0];
        }

        if ($input->getOption('metadata')) {
            $sField = $input->getOption('key');
            $sValue = $input->getOption('value');
            if (null === $sField || null === $sValue) {
                throw new RuntimeException(
                    'When searching using JSON fields the option --key and --value must be set.'
                );
            }

            $params['exact'] = (int)$input->getOption('exact');
            $params[iState::COLUMN_META_DATA] = 1;
            $params['key'] = $sField;
            $params['value'] = $sValue;
        }

        if ($input->getOption('extra')) {
            $sField = $input->getOption('key');
            $sValue = $input->getOption('value');
            if (null === $sField || null === $sValue) {
                throw new RuntimeException(
                    'When searching using JSON fields the option --key and --value must be set.'
                );
            }

            $params['exact'] = (int)$input->getOption('exact');
            $params[iState::COLUMN_EXTRA] = 1;
            $params['key'] = $sField;
            $params['value'] = $sValue;
        }

        if (null !== ($sort = $input->getOption('sort'))) {
            if (1 !== preg_match('/(?P<field>\w+)(:(?P<dir>\w+))?/', $sort, $matches)) {
                $output->writeln(
                    '<error>ERROR:</error> Invalid value for [<flag>--sort</flag>] expected value format is [<value>field:dir</value>].'
                );
                return self::INVALID;
            }

            $params['sort'] = r('{field}:{dir}', $matches);
        }

        $opts = [];

        if ($input->getOption('user')) {
            $opts['headers'] = [
                'X-User' => $input->getOption('user'),
            ];
        }
        
        $opts['query'] = $params;

        $response = APIRequest('GET', '/history', opts: $opts);

        if (Status::OK !== $response->status) {
            $output->writeln(r("<error>API error. {status}: {message}</error>", [
                'status' => $response->status->value,
                'message' => ag($response->body, 'error.message', 'Unknown error.')
            ]));
            return self::FAILURE;
        }

        $rows = ag($response->body, 'history', []);

        if (empty($rows)) {
            $output->writeln('<info>No results found.</info>');
            return self::SUCCESS;
        }

        if ('table' === $input->getOption('output')) {
            foreach ($rows as &$row) {
                $row[iState::COLUMN_UPDATED] = makeDate($row[iState::COLUMN_UPDATED])->getTimestamp();
                $row[iState::COLUMN_UPDATED_AT] = makeDate($row[iState::COLUMN_UPDATED_AT])->getTimestamp();
                $row[iState::COLUMN_CREATED_AT] = makeDate($row[iState::COLUMN_CREATED_AT])->getTimestamp();
                $row[iState::COLUMN_WATCHED] = (int)$row[iState::COLUMN_WATCHED];
                $entity = Container::get(iState::class)->fromArray($row);

                $row = [
                    'id' => $entity->id,
                    'type' => ucfirst($entity->type),
                    'title' => mb_substr($entity->getName(), 0, 59),
                    'via' => $entity->via ?? '??',
                    'date' => makeDate($entity->updated)->format('Y-m-d H:i:s T'),
                    'played' => $entity->isWatched() ? 'Yes' : 'No',
                    'progress' => $entity->hasPlayProgress() ? formatDuration($entity->getPlayProgress()) : 'None',
                    'event' => ag($entity->extra[$entity->via] ?? [], iState::COLUMN_EXTRA_EVENT, '-'),
                ];
            }
            unset($row);
        }

        $this->displayContent($rows, $output, $input->getOption('output'));

        if (null !== ($changeState = $input->getOption('mark-as')) && count($rows) >= 1) {
            $changeState = strtolower($changeState);

            if (!$input->getOption('no-interaction')) {
                $text = r(
                    '<question>Are you sure you want to mark [<notice>{total}</notice>] items as [<notice>{state}</notice>]</question> ? [<value>Y|N</value>] [<value>Default: No</value>]',
                    [
                        'total' => count($rows),
                        'state' => 'played' === $changeState ? 'Played' : 'Unplayed',
                    ]
                );

                $question = new ConfirmationQuestion($text . PHP_EOL . '> ', false);

                if (false === $this->getHelper('question')->ask($input, $output, $question)) {
                    return self::FAILURE;
                }
            }

            $userContext = getUserContext($input->getOption('user'), $this->mapper, $this->logger);

            foreach ($rows as $row) {
                $entity = $userContext->mapper->get(
                    Container::get(iState::class)->fromArray([iState::COLUMN_ID => $row['id']])
                );

                $entity->watched = 'played' === $changeState ? 1 : 0;
                $entity->updated = time();
                $entity->extra = ag_set($entity->getExtra(), $entity->via, [
                    iState::COLUMN_EXTRA_EVENT => 'cli.mark' . ($entity->isWatched() ? 'played' : 'unplayed'),
                    iState::COLUMN_EXTRA_DATE => (string)makeDate('now'),
                ]);

                $userContext->mapper->add($entity)->commit();

                queuePush($entity, userContext: $userContext);
            }

            $output->writeln(
                r('<info>Successfully marked [<notice>{total}</notice>] items as [<notice>{state}</notice>].', [
                    'total' => count($rows),
                    'state' => 'played' === $changeState ? 'Played' : 'Unplayed',
                ])
            );
        }

        return self::SUCCESS;
    }

    /**
     * Completes the given suggestions for a specific input.
     *
     * @param CompletionInput $input The completion input object.
     * @param CompletionSuggestions $suggestions The completion suggestions object.
     *
     * @return void
     */
    public function complete(CompletionInput $input, CompletionSuggestions $suggestions): void
    {
        parent::complete($input, $suggestions);

        if ($input->mustSuggestOptionValuesFor('via') || $input->mustSuggestOptionValuesFor('show-as')) {
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

            foreach ([iState::TYPE_MOVIE, iState::TYPE_EPISODE] as $name) {
                if (empty($currentValue) || str_starts_with($name, $currentValue)) {
                    $suggest[] = $name;
                }
            }

            $suggestions->suggestValues($suggest);
        }

        if ($input->mustSuggestOptionValuesFor('mark-as')) {
            $currentValue = $input->getCompletionValue();

            $suggest = [];

            foreach (['played', 'unplayed'] as $name) {
                if (empty($currentValue) || str_starts_with($name, $currentValue)) {
                    $suggest[] = $name;
                }
            }

            $suggestions->suggestValues($suggest);
        }

        if ($input->mustSuggestOptionValuesFor('sort')) {
            $currentValue = $input->getCompletionValue();

            $suggest = [];

            foreach (Index::COLUMNS_SORTABLE as $name) {
                foreach ([$name . ':desc', $name . ':asc'] as $subName) {
                    if (empty($currentValue) || true === str_starts_with($subName, $currentValue)) {
                        $suggest[] = $subName;
                    }
                }
            }

            $suggestions->suggestValues($suggest);
        }
    }
}
