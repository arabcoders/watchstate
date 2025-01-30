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
    private array $contentType = ['all', 'played', 'unplayed'];
    private array $sourceType = ['a', 'b'];

    /**
     * Class Constructor.
     */
    public function __construct(private iLogger $logger)
    {
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
        $this->setName(self::ROUTE)
            ->addOption('save', 's', InputOption::VALUE_REQUIRED, 'Save difference in a file.')
            ->addOption('content', 'c', InputOption::VALUE_REQUIRED, 'Save mode, can be all, played, unplayed.', 'all')
            ->addOption('source', 'S', InputOption::VALUE_REQUIRED, 'Source of truth, can be a or b.', 'a')
            ->addArgument(
                'files',
                InputArgument::IS_ARRAY,
                'The files to compare, the first is the original and the second is the new file to compare.'
            )
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
        $files = $input->getArgument('files');
        $fp = null;

        if (null !== ($save = $input->getOption('save'))) {
            $fp = new Stream($save, 'wb+');
            $fp->write('[');
        }

        if (false === in_array($input->getOption('content'), $this->contentType, true)) {
            $output->writeln('<error>Invalid content type provided. Please provide a valid content type.</error>');
            return self::FAILURE;
        }

        if (false === in_array($input->getOption('source'), $this->sourceType, true)) {
            $output->writeln('<error>Invalid source type provided. Please provide a valid source type.</error>');
            return self::FAILURE;
        }

        if (2 !== count($files)) {
            $output->writeln('<error>Invalid number of files provided. Please provide 2 files to compare.</error>');
            return self::FAILURE;
        }

        $exists = true;
        foreach ($files as $file) {
            if (!file_exists($file)) {
                $output->writeln("<error>File not found: {$file}</error>");
                $exists = false;
            }
        }

        if (false === $exists) {
            return self::FAILURE;
        }

        $mapper1 = $mapper2 = null;

        foreach ($files as $file) {
            $mapper = new RestoreMapper($this->logger, $file);

            if (null === $mapper1) {
                $mapper1 = $mapper;
            } else {
                $mapper2 = $mapper;
            }

            $time = microtime(true);
            $this->logger->info("Loading '{file}' into memory.", ['file' => $file]);
            $mapper->loadData();
            $end = microtime(true);
            $this->logger->info("Finished parsing data from '{file}' in '{time}s'.", [
                'file' => $file,
                'time' => round($end - $time, 2),
            ]);
        }

        $this->logger->notice("Comparing '{memory}' of data. Please wait.", ['memory' => getMemoryUsage()]);

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

            if ($entity2->isWatched() !== $entity->isWatched()) {
                $data['changed'][] = [
                    'title' => $entity->getName(),
                    'a' => $entity->isWatched(),
                    'b' => $entity2->isWatched(),
                    'entity_a' => $entity,
                    'entity_b' => $entity2,
                ];
            }
        }

        foreach ($mapper2->getObjects() as $entity) {
            if (null === $mapper1->get($entity)) {
                $data['not_in_a'][] = [
                    'title' => $entity->getName(),
                    'status' => $entity->isWatched(),
                ];
            }
        }

        if (null !== $fp && count($data['changed']) > 0) {
            $this->saveContent($input, $data['changed'], $fp);
            $fp->close();
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

    private function saveContent(iInput $input, array $data, Stream $fp): void
    {
        $source = $input->getOption('source');
        $contentType = $input->getOption('content');

        $this->logger->notice("Saving the difference 'Source: {source}, Content: {content}' to '{file}'.", [
            'file' => $fp->getMetadata('uri'),
            'source' => $source,
            'content' => $contentType,
        ]);

        foreach ($data as $row) {
            $entity = $row['a' === $source ? 'entity_a' : 'entity_b'];
            assert($entity instanceof iState);

            if ('played' === $contentType && false === $entity->isWatched()) {
                continue;
            }

            if ('unplayed' === $contentType && true === $entity->isWatched()) {
                continue;
            }

            $fp->write(PHP_EOL . $this->processEntity($entity) . ',');
        }

        $fp->seek(-1, SEEK_END);
        $fp->write(PHP_EOL . ']');
    }

    private function processEntity(iState $entity): string
    {
        $arr = [
            iState::COLUMN_TYPE => $entity->type,
            iState::COLUMN_WATCHED => (int)$entity->isWatched(),
            iState::COLUMN_UPDATED => makeDate($entity->updated)->getTimestamp(),
            iState::COLUMN_META_SHOW => '',
            iState::COLUMN_TITLE => trim($entity->title),
        ];

        if ($entity->isEpisode()) {
            $arr[iState::COLUMN_META_SHOW] = trim($entity->title);
            $arr[iState::COLUMN_TITLE] = trim(
                ag(
                    $entity->getMetadata($entity->via),
                    iState::COLUMN_META_DATA_EXTRA . '.' .
                    iState::COLUMN_META_DATA_EXTRA_TITLE,
                    $entity->season . 'x' . $entity->episode,
                )
            );
            $arr[iState::COLUMN_SEASON] = $entity->season;
            $arr[iState::COLUMN_EPISODE] = $entity->episode;
        } else {
            unset($arr[iState::COLUMN_META_SHOW]);
        }

        $arr[iState::COLUMN_YEAR] = $entity->year;

        $arr[iState::COLUMN_GUIDS] = array_filter(
            $entity->getGuids(),
            fn($key) => str_contains($key, 'guid_'),
            ARRAY_FILTER_USE_KEY
        );

        if ($entity->isEpisode()) {
            $arr[iState::COLUMN_PARENT] = array_filter(
                $entity->getParentGuids(),
                fn($key) => str_contains($key, 'guid_'),
                ARRAY_FILTER_USE_KEY
            );
        }

        if ($entity->hasPlayProgress()) {
            $arr[iState::COLUMN_META_DATA_PROGRESS] = $entity->getPlayProgress();
        }


        return json_encode($arr, JSON_INVALID_UTF8_IGNORE | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }

    public function complete(CompletionInput $input, CompletionSuggestions $suggestions): void
    {
        if ($input->mustSuggestArgumentValuesFor('files')) {
            $realValue = afterLast($input->getCompletionValue(), '/');
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

            $suggest = [];

            foreach (new DirectoryIterator($dirPath) as $name) {
                if ($name->isDot()) {
                    continue;
                }

                if (empty($realValue) || true === str_starts_with($name->getFilename(), $realValue)) {
                    $suggest[] = $dirPath . DIRECTORY_SEPARATOR . $name->getFilename();
                }
            }

            $suggestions->suggestValues($suggest);
        }

        parent::complete($input, $suggestions);
    }
}
