<?php

declare(strict_types=1);

namespace App\Commands\System;

use App\Command;
use App\Libs\Attributes\Route\Cli;
use App\Libs\Entity\StateInterface as iState;
use App\Libs\Mappers\Import\RestoreMapper;
use App\Libs\Stream;
use DirectoryIterator;
use JsonMachine\Exception\InvalidArgumentException;
use Psr\Log\LoggerInterface as iLogger;
use Symfony\Component\Console\Completion\CompletionInput;
use Symfony\Component\Console\Completion\CompletionSuggestions;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface as iInput;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface as iOutput;

/**
 * Class DiffCommand
 *
 * This command is used to compare 2 backup files for difference.
 */
#[Cli(command: self::ROUTE)]
final class DiffCommand extends Command
{
    public const string ROUTE = 'system:diff';

    private array $filterType = ['all', 'played', 'unplayed'];
    private array $sourceType = ['a', 'b'];

    /**
     * Class Constructor.
     */
    public function __construct(
        private iLogger $logger,
    ) {
        set_time_limit(0);
        ini_set('memory_limit', '-1');
        parent::__construct();
    }

    /**
     * Configure the Tinker command.
     *
     * @return void
     */
    protected function configure(): void
    {
        $this
            ->setName(self::ROUTE)
            ->addOption('save', 's', InputOption::VALUE_REQUIRED, 'Save difference in a file.')
            ->addOption('filter', 'f', InputOption::VALUE_REQUIRED, 'Filter by all, played or unplayed.', 'all')
            ->addOption('source', 'S', InputOption::VALUE_REQUIRED, 'Source of truth, can be a or b.', 'a')
            ->addArgument('a', InputArgument::REQUIRED, 'Source A')
            ->addArgument('b', InputArgument::REQUIRED, 'Source B')
            ->setDescription('Compare 2 backup files for difference');
    }

    /**
     * Run the interactive shell.
     *
     * @param iInput $input The input object containing the command input.
     * @param iOutput $output The output object for writing command output.
     *
     * @return int Returns 0 on success or an error code on failure.
     * @throws InvalidArgumentException
     */
    protected function execute(iInput $input, iOutput $output): int
    {
        $filter = $input->getOption('filter');
        $saveFile = $input->getOption('save');
        $source = $input->getOption('source');

        if (false === in_array($filter, $this->filterType, true)) {
            $output->writeln('<error>Invalid filter type provided. Please provide a valid filter type.</error>');
            return self::FAILURE;
        }

        if (false === in_array($input->getOption('source'), $this->sourceType, true)) {
            $output->writeln('<error>Invalid source of truth. Please provide a valid source type.</error>');
            return self::FAILURE;
        }

        $a = $input->getArgument('a');
        $b = $input->getArgument('b');

        if (false === is_string($a) || false === is_string($b)) {
            $output->writeln('<error>Invalid file path provided. Please provide a valid file path.</error>');
            return self::FAILURE;
        }

        if (false === file_exists($a) || false === is_readable($a)) {
            $output->writeln(r("<error>ERROR: source A '{file}' not found or is unreadable.</error>", ['file' => $a]));
            return self::FAILURE;
        }

        if (false === file_exists($b) || false === is_readable($b)) {
            $output->writeln(r("<error>ERROR: source B '{file}' not found or is unreadable.</error>", ['file' => $b]));
            return self::FAILURE;
        }

        if ($a === $b) {
            $output->writeln(r('<error>ERROR: source A and source B are the same file.</error>'));
            return self::FAILURE;
        }

        // -- source A.
        $mapper1 = new RestoreMapper($this->logger, $a);
        $this->logger->info("Loading source A '{file}' into memory.", ['file' => $a]);
        $time = microtime(true);
        $mapper1->loadData();
        $end = microtime(true);
        $this->logger->info("Finished parsing data from source A '{file}' in '{time}s'.", [
            'file' => $a,
            'time' => round($end - $time, 2),
        ]);

        // -- source B.
        $mapper2 = new RestoreMapper($this->logger, $b);
        $this->logger->info("Loading source B '{file}' into memory.", ['file' => $b]);
        $time = microtime(true);
        $mapper2->loadData();
        $end = microtime(true);
        $this->logger->info("Finished parsing data from source B '{file}' in '{time}s'.", [
            'file' => $b,
            'time' => round($end - $time, 2),
        ]);

        $this->logger->notice("Comparing '{memory}' of data. Please wait.", ['memory' => get_memory_usage()]);

        $data = [
            'changed' => [],
            'not_in_a' => [],
            'not_in_b' => [],
        ];

        foreach ($mapper1->getObjects() as $entity) {
            if (null === ($entity2 = $mapper2->get($entity))) {
                $data['not_in_b'][] = [
                    'title' => $entity->getName(),
                    'status' => $entity->isWatched(),
                ];
                continue;
            }

            $src = 'a' === $source ? $entity : $entity2;

            if ($entity2->isWatched() === $entity->isWatched()) {
                continue;
            }

            if ('played' === $filter && false === $src->isWatched()) {
                continue;
            }

            if ('unplayed' === $filter && true === $src->isWatched()) {
                continue;
            }

            $data['changed'][] = [
                'title' => $src->getName(),
                'a' => $entity->isWatched(),
                'b' => $entity2->isWatched(),
                'entity_a' => $entity,
                'entity_b' => $entity2,
            ];
        }

        foreach ($mapper2->getObjects() as $entity) {
            if (null !== $mapper1->get($entity)) {
                continue;
            }

            $data['not_in_a'][] = [
                'title' => $entity->getName(),
                'status' => $entity->isWatched(),
            ];
        }

        if (null !== $saveFile && count($data['changed']) > 0) {
            $this->saveContent($data['changed'], $saveFile, $filter, $source);
        }

        if ('table' === $input->getOption('output')) {
            $newData = [];
            foreach (ag($data, 'changed', []) as $row) {
                $newData[] = [
                    'Title' => $row['title'],
                    '[O] Played' => $row['a'] ? 'Yes' : 'No',
                    '[N] Played' => $row['b'] ? 'Yes' : 'No',
                ];
            }
            $data = $newData;
        }

        $this->displayContent($data, $output, $input->getOption('output'));

        return self::SUCCESS;
    }

    private function saveContent(array $data, string $file, string $filter, string $source): void
    {
        $this->logger->notice("Saving the difference 'Source: {source}, filter: {filter}' to '{file}'.", [
            'file' => $file,
            'source' => $source,
            'filter' => $filter,
        ]);

        $fp = Stream::make($file, 'wb');
        $fp->write('[');

        foreach ($data as $row) {
            $entity = $row['a' === $source ? 'entity_a' : 'entity_b'];
            assert($entity instanceof iState, 'Expected state entity for diff output.');

            if ('played' === $filter && false === $entity->isWatched()) {
                continue;
            }

            if ('unplayed' === $filter && true === $entity->isWatched()) {
                continue;
            }

            $fp->write(PHP_EOL . $this->processEntity($entity) . ',');
        }

        $fp->seek(-1, SEEK_END);
        $fp->write(PHP_EOL . ']');
        $fp->close();
    }

    private function processEntity(iState $entity): string
    {
        $arr = [
            iState::COLUMN_TYPE => $entity->type,
            iState::COLUMN_WATCHED => (int) $entity->isWatched(),
            iState::COLUMN_UPDATED => make_date($entity->updated)->getTimestamp(),
            iState::COLUMN_META_SHOW => '',
            iState::COLUMN_TITLE => trim($entity->title),
        ];

        if ($entity->isEpisode()) {
            $arr[iState::COLUMN_META_SHOW] = trim($entity->title);
            $arr[iState::COLUMN_TITLE] = trim(
                ag(
                    $entity->getMetadata($entity->via),
                    iState::COLUMN_META_DATA_EXTRA . '.' . iState::COLUMN_META_DATA_EXTRA_TITLE,
                    $entity->season . 'x' . $entity->episode,
                ),
            );
            $arr[iState::COLUMN_SEASON] = $entity->season;
            $arr[iState::COLUMN_EPISODE] = $entity->episode;
        } else {
            unset($arr[iState::COLUMN_META_SHOW]);
        }

        $arr[iState::COLUMN_YEAR] = $entity->year;

        $arr[iState::COLUMN_GUIDS] = array_filter(
            $entity->getGuids(),
            static fn($key) => str_contains($key, 'guid_'),
            ARRAY_FILTER_USE_KEY,
        );

        if ($entity->isEpisode()) {
            $arr[iState::COLUMN_PARENT] = array_filter(
                $entity->getParentGuids(),
                static fn($key) => str_contains($key, 'guid_'),
                ARRAY_FILTER_USE_KEY,
            );
        }

        if ($entity->hasPlayProgress()) {
            $arr[iState::COLUMN_META_DATA_PROGRESS] = $entity->getPlayProgress();
        }

        return json_encode($arr, JSON_INVALID_UTF8_IGNORE | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }

    public function complete(CompletionInput $input, CompletionSuggestions $suggestions): void
    {
        if (
            $input->mustSuggestArgumentValuesFor('a')
            || $input->mustSuggestArgumentValuesFor(
                'b',
            )
            || $input->mustSuggestOptionValuesFor('save')
        ) {
            $realValue = after_last($input->getCompletionValue(), '/');
            $filePath = $input->getCompletionValue();

            $dirPath = getcwd();

            if (!empty($filePath)) {
                if (str_starts_with($filePath, '.')) {
                    $filePath = getcwd() . DIRECTORY_SEPARATOR . $filePath;
                }

                $dirPath = $filePath;

                if (false === is_dir($dirPath)) {
                    $dirPath = dirname($dirPath);
                }

                $dirPath = realpath($dirPath);
            }
            if (false === $dirPath) {
                return;
            }
            $suggest = [];

            foreach (new DirectoryIterator($dirPath) as $name) {
                if ($name->isDot()) {
                    continue;
                }

                if (empty($realValue) || true === str_starts_with($name->getFilename(), $realValue)) {
                    if ($name->isDir()) {
                        $suggest[] = $dirPath . DIRECTORY_SEPARATOR . $name->getFilename() . DIRECTORY_SEPARATOR;
                        continue;
                    }
                    $suggest[] = $dirPath . DIRECTORY_SEPARATOR . $name->getFilename();
                }
            }

            $suggestions->suggestValues($suggest);
        }

        if ($input->mustSuggestOptionValuesFor('filter')) {
            $suggestions->suggestValues($this->filterType);
        }

        if ($input->mustSuggestOptionValuesFor('source')) {
            $suggestions->suggestValues($this->sourceType);
        }

        parent::complete($input, $suggestions);
    }
}
