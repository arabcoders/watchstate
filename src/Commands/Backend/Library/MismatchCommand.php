<?php

declare(strict_types=1);

namespace App\Commands\Backend\Library;

use App\Command;
use App\Libs\Config;
use App\Libs\Options;
use App\Libs\Routable;
use RuntimeException;
use Symfony\Component\Console\Completion\CompletionInput;
use Symfony\Component\Console\Completion\CompletionSuggestions;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Yaml\Yaml;
use Throwable;

#[Routable(command: self::ROUTE)]
final class MismatchCommand extends Command
{
    public const ROUTE = 'backend:library:mismatch';

    protected array $methods = [
        'similarity',
        'levenshtein',
    ];

    protected function configure(): void
    {
        $this->setName(self::ROUTE)
            ->setDescription(
                'Find possible mis-identified item in a library. This only works for Media that follow Plex naming format.'
            )
            ->addOption('show-all', null, InputOption::VALUE_NONE, 'Show all items regardless of status.')
            ->addOption('percentage', 'p', InputOption::VALUE_OPTIONAL, 'Acceptable percentage.', 50.0)
            ->addOption(
                'method',
                'm',
                InputOption::VALUE_OPTIONAL,
                sprintf('Comparison method. Can be [%s].', implode(', ', $this->methods)),
                $this->methods[0]
            )
            ->addOption(
                'timeout',
                null,
                InputOption::VALUE_OPTIONAL,
                'Request timeout in seconds.',
                Config::get('http.default.options.timeout')
            )
            ->addOption('include-raw-response', null, InputOption::VALUE_NONE, 'Include unfiltered raw response.')
            ->addOption('config', 'c', InputOption::VALUE_REQUIRED, 'Use Alternative config file.')
            ->addArgument('backend', InputArgument::REQUIRED, 'Backend name.')
            ->addArgument('id', InputArgument::REQUIRED, 'Library id.');
    }

    protected function runCommand(InputInterface $input, OutputInterface $output): int
    {
        $mode = $input->getOption('output');
        $showAll = $input->getOption('show-all');
        $percentage = $input->getOption('percentage');
        $backend = $input->getArgument('backend');
        $id = $input->getArgument('id');

        // -- Use Custom servers.yaml file.
        if (($config = $input->getOption('config'))) {
            try {
                Config::save('servers', Yaml::parseFile($this->checkCustomServersFile($config)));
            } catch (RuntimeException $e) {
                $arr = [
                    'error' => $e->getMessage()
                ];
                $this->displayContent('table' === $mode ? [$arr] : $arr, $output, $mode);
                return self::FAILURE;
            }
        }

        try {
            $backendOpts = $opts = $list = [];

            if ($input->getOption('timeout')) {
                $backendOpts = ag_set($opts, 'client.timeout', (float)$input->getOption('timeout'));
            }

            if ($input->getOption('trace')) {
                $backendOpts = ag_set($opts, 'options.' . Options::DEBUG_TRACE, true);
            }

            if ($input->getOption('include-raw-response')) {
                $opts[Options::RAW_RESPONSE] = true;
            }

            $opts[Options::MISMATCH_DEEP_SCAN] = true;

            foreach ($this->getBackend($backend, $backendOpts)->getLibrary(id: $id, opts: $opts) as $item) {
                $processed = $this->compare(item: $item, method: $input->getOption('method'));

                if (!$showAll && (empty($processed) || $processed['percent'] >= (float)$percentage)) {
                    continue;
                }

                $list[] = $processed;
            }
        } catch (Throwable $e) {
            $arr = [
                'error' => $e->getMessage(),
            ];

            if ('table' !== $mode) {
                $arr['exception'] = [
                    'file' => $e->getFile(),
                    'line' => $e->getLine(),
                    'item' => $item ?? [],
                ];

                if (!empty($item)) {
                    $arr['item'] = $item;
                }
            }

            $this->displayContent('table' === $mode ? [$arr] : $arr, $output, $mode);

            return self::FAILURE;
        }

        if (empty($list)) {
            $arr = [
                'info' => sprintf(
                    'No mis-identified items were found in [%s] library [%s] using given parameters.',
                    $backend,
                    $id
                )
            ];

            $this->displayContent('table' === $mode ? [$arr] : $arr, $output, $mode);
            return self::SUCCESS;
        }

        if ('table' === $mode) {
            $forTable = [];

            foreach ($list as $item) {
                $forTable[] = [
                    'id' => ag($item, 'id'),
                    'type' => ag($item, 'type'),
                    'title' => ag($item, 'title'),
                    'year' => ag($item, 'year'),
                    'percent' => ag($item, 'percent') . '%',
                    'path' => ag($item, 'path'),
                ];
            }

            $list = $forTable;
        }

        $this->displayContent($list, $output, $mode);

        return self::SUCCESS;
    }

    private function compare(array $item, string $method): array
    {
        if (empty($item)) {
            return [];
        }

        if (null === ($paths = ag($item, 'match.paths', [])) || empty($paths)) {
            return [];
        }

        if (null === ($titles = ag($item, 'match.titles', [])) || empty($titles)) {
            return [];
        }

        if (count($item['guids']) < 1) {
            $item['guids'] = 'None';
        }

        $toLower = fn(string $text, bool $isASCII = false) => trim($isASCII ? strtolower($text) : mb_strtolower($text));

        $item['percent'] = $percent = 0.0;

        foreach ($paths as $path) {
            $pathFull = ag($path, 'full');
            $pathShort = ag($path, 'short');

            if (empty($pathFull) || empty($pathShort)) {
                continue;
            }

            foreach ($titles as $title) {
                $isASCII = mb_detect_encoding($pathShort, 'ASCII') && mb_detect_encoding($title, 'ASCII');

                $title = $toLower($this->formatName(name: $title), isASCII: $isASCII);
                $pathShort = $toLower($this->formatName(name: $pathShort), isASCII: $isASCII);

                if (1 === preg_match('/\((\d{4})\)/', basename($pathFull), $match)) {
                    $withYear = true;
                    if (ag($item, 'year') && false === str_contains($title, (string)ag($item, 'year'))) {
                        $title .= ' ' . ag($item, 'year');
                    }
                } else {
                    $withYear = false;
                }

                if (true === str_starts_with($pathShort, $title)) {
                    $percent = 100.0;
                }

                if (true === $isASCII) {
                    similar_text($pathShort, $title, $similarity);
                    $levenshtein = levenshtein($pathShort, $title);
                } else {
                    $this->mb_similar_text($pathShort, $title, $similarity);
                    $levenshtein = $this->mb_levenshtein($pathShort, $title);
                }

                $levenshtein = $this->toPercentage($levenshtein, $pathShort, $title);

                switch ($method) {
                    default:
                    case 'similarity':
                        if ($similarity > $percent) {
                            $percent = $similarity;
                        }
                        break;
                    case 'levenshtein':
                        if ($similarity > $percent) {
                            $percent = $levenshtein;
                        }
                        break;
                }

                if (round($percent, 3) > $item['percent']) {
                    $item['percent'] = round($percent, 3);
                }

                $item['matches'][] = [
                    'path' => $pathShort,
                    'title' => $title,
                    'type' => $isASCII ? 'ascii' : 'unicode',
                    'methods' => [
                        'similarity' => round($similarity, 3),
                        'levenshtein' => round($levenshtein, 3),
                        'startWith' => str_starts_with($pathShort, $title),
                    ],
                    'year' => [
                        'inPath' => $withYear,
                        'parsed' => isset($match[1]) ? (int)$match[1] : 'No',
                        'source' => ag($item, 'year', 'No'),
                    ],
                ];
            }
        }

        if (count($paths) <= 2 && null !== ($paths[0]['full'] ?? null)) {
            $item['path'] = basename($paths[0]['full']);
        }

        return $item;
    }

    public function complete(CompletionInput $input, CompletionSuggestions $suggestions): void
    {
        parent::complete($input, $suggestions);

        $methods = [
            'method' => 'methods',
        ];

        foreach ($methods as $key => $of) {
            if ($input->mustSuggestOptionValuesFor($key)) {
                $currentValue = $input->getCompletionValue();

                $suggest = [];

                foreach ($this->{$of} as $name) {
                    if (empty($currentValue) || str_starts_with($name, $currentValue)) {
                        $suggest[] = $name;
                    }
                }

                $suggestions->suggestValues($suggest);
            }
        }
    }

    private function formatName(string $name): string
    {
        return trim(
            preg_replace(
                '/\s+/',
                ' ',
                str_replace(
                    [
                        '?',
                        ':',
                        '(',
                        '[',
                        ']',
                        ')',
                        ',',
                        '|',
                        '%',
                        '.',
                        '–',
                        '-',
                        "'",
                        '"',
                        '+',
                        '/',
                        ';',
                        '&',
                        '_',
                        '!',
                        '*',
                    ],
                    ' ',
                    $name
                )
            )
        );
    }

    /**
     * Implementation of `mb_similar_text()`.
     *
     * (c) Antal Áron <antalaron@antalaron.hu>
     *
     * @see http://php.net/manual/en/function.similar-text.php
     * @see http://locutus.io/php/strings/similar_text/
     *
     * @param string $str1
     * @param string $str2
     * @param float|null $percent
     *
     * @return int
     */
    private function mb_similar_text(string $str1, string $str2, float|null &$percent = null): int
    {
        if (0 === mb_strlen($str1) + mb_strlen($str2)) {
            $percent = 0.0;

            return 0;
        }

        $pos1 = $pos2 = $max = 0;
        $l1 = mb_strlen($str1);
        $l2 = mb_strlen($str2);

        for ($p = 0; $p < $l1; ++$p) {
            for ($q = 0; $q < $l2; ++$q) {
                /** @noinspection LoopWhichDoesNotLoopInspection */
                /** @noinspection MissingOrEmptyGroupStatementInspection */
                for (
                    $l = 0; ($p + $l < $l1) && ($q + $l < $l2) && mb_substr($str1, $p + $l, 1) === mb_substr(
                    $str2,
                    $q + $l,
                    1
                ); ++$l
                ) {
                    // nothing to do
                }
                if ($l > $max) {
                    $max = $l;
                    $pos1 = $p;
                    $pos2 = $q;
                }
            }
        }

        $similarity = $max;
        if ($similarity) {
            if ($pos1 && $pos2) {
                $similarity += $this->mb_similar_text(mb_substr($str1, 0, $pos1), mb_substr($str2, 0, $pos2));
            }
            if (($pos1 + $max < $l1) && ($pos2 + $max < $l2)) {
                $similarity += $this->mb_similar_text(
                    mb_substr($str1, $pos1 + $max, $l1 - $pos1 - $max),
                    mb_substr($str2, $pos2 + $max, $l2 - $pos2 - $max)
                );
            }
        }

        $percent = ($similarity * 200.0) / ($l1 + $l2);

        return $similarity;
    }

    private function mb_levenshtein(string $str1, string $str2)
    {
        $length1 = mb_strlen($str1, 'UTF-8');
        $length2 = mb_strlen($str2, 'UTF-8');

        if ($length1 < $length2) {
            return $this->mb_levenshtein($str2, $str1);
        }

        if (0 === $length1) {
            return $length2;
        }

        if ($str1 === $str2) {
            return 0;
        }

        $prevRow = range(0, $length2);

        for ($i = 0; $i < $length1; $i++) {
            $currentRow = [];
            $currentRow[0] = $i + 1;
            $c1 = mb_substr($str1, $i, 1, 'UTF-8');

            for ($j = 0; $j < $length2; $j++) {
                $c2 = mb_substr($str2, $j, 1, 'UTF-8');
                $insertions = $prevRow[$j + 1] + 1;
                $deletions = $currentRow[$j] + 1;
                $substitutions = $prevRow[$j] + (($c1 !== $c2) ? 1 : 0);
                $currentRow[] = min($insertions, $deletions, $substitutions);
            }

            $prevRow = $currentRow;
        }
        return $prevRow[$length2];
    }

    private function toPercentage($base, $str1, $str2, bool $isASCII = false): float
    {
        $length = fn(string $text) => $isASCII ? mb_strlen($text, 'UTF-8') : strlen($text);

        return (1 - $base / max($length($str1), $length($str2))) * 100;
    }
}
