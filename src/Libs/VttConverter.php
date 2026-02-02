<?php

declare(strict_types=1);

namespace App\Libs;

use InvalidArgumentException;

/**
 * Class VttConverter
 *
 * Based on {@link https://github.com/mantas-done/subtitles/blob/master/src/Code/Converters/VttConverter.php}
 */
final readonly class VttConverter
{
    private const string TIME_FORMAT = '/(?:\d{2}[:;])(?:\d{1,2}[:;])(?:\d{1,2}[:;])\d{1,3}|(?:\d{1,2}[:;])?(?:\d{1,2}[:;])\d{1,3}(?:[.,]\d+)?(?!\d)|\d{1,5}[.,]\d{1,3}/';

    public static function parse(string $contents): array
    {
        $content = self::removeComments($contents);

        $lines = mb_split("\n", $content);
        $colonCount = 1;
        $internalFormat = [];
        $i = -1;
        $seenFirstTimestamp = false;
        $lastLineWasEmpty = true;

        foreach ($lines as $line) {
            $parts = self::getLineParts($line, $colonCount, 2);

            if (false === $seenFirstTimestamp && $parts['start'] && $parts['end'] && str_contains($line, '-->')) {
                $seenFirstTimestamp = true;
            }

            if (!$seenFirstTimestamp) {
                continue;
            }

            if ($parts['start'] && $parts['end'] && true === str_contains($line, '-->')) {
                $i++;
                $internalFormat[$i]['start'] = self::vttTimeToInternal($parts['start']);
                $internalFormat[$i]['end'] = self::vttTimeToInternal($parts['end']);
                $internalFormat[$i]['lines'] = [];

                // styles
                preg_match(
                    '/((?:\d{1,2}:){1,2}\d{2}\.\d{1,3})\s+-->\s+((?:\d{1,2}:){1,2}\d{2}\.\d{1,3}) *(.*)/',
                    $line,
                    $matches,
                );

                if (isset($matches[3]) && ltrim($matches[3])) {
                    $internalFormat[$i]['vtt']['settings'] = ltrim($matches[3]);
                }

                // cue
                if (!$lastLineWasEmpty && isset($internalFormat[$i - 1])) {
                    $count = count($internalFormat[$i - 1]['lines']);
                    if ($count === 1) {
                        $internalFormat[$i - 1]['lines'][0] = '';
                    } else {
                        unset($internalFormat[$i - 1]['lines'][$count - 1]);
                    }
                }
            } elseif ('' !== trim($line)) {
                $textLine = $line;
                // speaker
                $speaker = null;
                if (preg_match('~^<v(?: (.*?))?>(.+)(</v>)?~', $textLine, $matches)) {
                    $speaker = $matches[1] ?? null;
                    $textLine = $matches[2];
                }

                // html
                $textLine = strip_tags($textLine);

                $internalFormat[$i]['lines'][] = $textLine;
                $internalFormat[$i]['vtt']['speakers'][] = $speaker;
            }

            // remove if empty speakers array.
            if (isset($internalFormat[$i]['vtt']['speakers'])) {
                $is_speaker = false;

                foreach ($internalFormat[$i]['vtt']['speakers'] as $tmp_speaker) {
                    if ($tmp_speaker === null) {
                        continue;
                    }

                    $is_speaker = true;
                }

                if (false === $is_speaker) {
                    unset($internalFormat[$i]['vtt']['speakers']);
                    if (0 === count($internalFormat[$i]['vtt'])) {
                        /** @noinspection PhpConditionAlreadyCheckedInspection */
                        unset($internalFormat[$i]['vtt']);
                    }
                }
            }

            $lastLineWasEmpty = '' === trim($line);
        }

        return $internalFormat;
    }

    public static function export(array $data): string
    {
        $fileContent = "WEBVTT\r\n\r\n";

        foreach ($data as $block) {
            $start = self::internalTimeToVtt($block['start']);
            $end = self::internalTimeToVtt($block['end']);
            $newLines = '';

            foreach ($block['lines'] as $i => $line) {
                if (isset($block['vtt']['speakers'][$i])) {
                    $speaker = $block['vtt']['speakers'][$i];
                    $newLines .= '<v ' . $speaker . '>' . $line . "</v>\r\n";
                } else {
                    $newLines .= $line . "\r\n";
                }
            }

            $vttSettings = '';
            if (isset($block['vtt']['settings'])) {
                $vttSettings = ' ' . $block['vtt']['settings'];
            }

            $fileContent .= $start . ' --> ' . $end . $vttSettings . "\r\n";
            $fileContent .= $newLines;
            $fileContent .= "\r\n";
        }

        return trim($fileContent);
    }

    private static function vttTimeToInternal($vtt_time): float
    {
        $corrected_time = str_replace(',', '.', $vtt_time);
        $parts = explode('.', $corrected_time);

        // parts[0] could be mm:ss or hh:mm:ss format -> always use hh:mm:ss
        $parts[0] = 2 === substr_count($parts[0], ':') ? $parts[0] : '00:' . $parts[0];

        if (!isset($parts[1])) {
            throw new InvalidArgumentException(r("Invalid timestamp - time doesn't have milliseconds: '{time}'.", [
                'time' => $vtt_time,
            ]));
        }

        $onlySeconds = strtotime("1970-01-01 {$parts[0]} UTC");
        $milliseconds = (float) ('0.' . $parts[1]);

        return $onlySeconds + $milliseconds;
    }

    private static function internalTimeToVtt(string|float $internal_time): string
    {
        $parts = explode('.', (string) $internal_time); // 1.23
        $whole = (float) $parts[0]; // 1
        $decimal = isset($parts[1]) ? substr($parts[1], 0, 3) : '0'; // 23
        $seconds = (int) floor($whole);

        return gmdate('H:i:s', $seconds) . '.' . str_pad($decimal, 3, '0', STR_PAD_RIGHT);
    }

    private static function removeComments($content): string
    {
        $lines = mb_split("\n", $content);
        $lines = array_map('trim', $lines);
        $newLines = [];
        $isComment = false;

        foreach ($lines as $line) {
            if ($isComment && strlen($line)) {
                continue;
            }
            if (true === str_starts_with($line, 'NOTE ')) {
                $isComment = true;
                continue;
            }
            $isComment = false;
            $newLines[] = $line;
        }

        return implode("\n", $newLines);
    }

    private static function getLineParts(string $line, int $colonCount, int $timestampCount): array
    {
        $matches = [
            'start' => null,
            'end' => null,
            'text' => null,
        ];

        $timestamps = self::timestampsFromLine($line);

        // there shouldn't be any text before the timestamp
        // if there is text before it, then it is not a timestamp
        $rightTimestamp = '';

        if (
            isset($timestamps['start'])
            && (
                substr_count($timestamps['start'], ':') >= $colonCount
                || substr_count(
                    $timestamps['start'],
                    ';',
                ) >= $colonCount
            )
        ) {
            $textBeforeTimestamp = substr($line, 0, strpos($line, $timestamps['start']));
            if (!self::hasText($textBeforeTimestamp)) {
                // start
                $matches['start'] = $timestamps['start'];
                $rightTimestamp = $matches['start'];
                if (
                    $timestampCount === 2
                    && isset($timestamps['end'])
                    && (
                        substr_count(
                            $timestamps['end'],
                            ':',
                        ) >= $colonCount
                        || substr_count($timestamps['end'], ';') >= $colonCount
                    )
                ) {
                    // end
                    $matches['end'] = $timestamps['end'];
                    $rightTimestamp = $matches['end'];
                }
            }
        }

        // check if there is any text after the timestamp
        if ($rightTimestamp) {
            $tmpParts = explode($rightTimestamp, $line); // if start and end timestamp are equals
            $rightText = end($tmpParts); // take text after the end timestamp
            if (self::hasText($rightText) || self::hasDigit($rightText)) {
                $matches['text'] = trim($rightText);
            }
        } else {
            $matches['text'] = $line;
        }

        return $matches;
    }

    private static function timestampsFromLine(string $line): array
    {
        preg_match_all(self::TIME_FORMAT . 'm', $line, $timestamps);

        $result = [
            'start' => null,
            'end' => null,
        ];

        if (isset($timestamps[0][0])) {
            $result['start'] = $timestamps[0][0];
        }

        if (isset($timestamps[0][1])) {
            $result['end'] = $timestamps[0][1];
        }

        if ($result['start']) {
            $textBeforeTimestamp = substr($line, 0, strpos($line, $result['start']));
            if (self::hasText($textBeforeTimestamp)) {
                $result = [
                    'start' => null,
                    'end' => null,
                ];
            }
        }

        return $result;
    }

    private static function hasText(string $line): bool
    {
        return 1 === preg_match('/\p{L}/u', $line);
    }

    private static function hasDigit(string $line): bool
    {
        return 1 === preg_match('/\d/', $line);
    }
}
