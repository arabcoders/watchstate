<?php

namespace App\Libs;

use InvalidArgumentException;

/**
 * Class VttConverter
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
                    $matches
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
                if (preg_match('/<v(?: (.*?))?>((?:.*?)<\/v>)/', $textLine, $matches)) {
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
                    if ($tmp_speaker !== null) {
                        $is_speaker = true;
                    }
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
            throw new InvalidArgumentException("Invalid timestamp - time doesn't have milliseconds: " . $vtt_time);
        }

        $only_seconds = strtotime("1970-01-01 {$parts[0]} UTC");
        $milliseconds = (float)('0.' . $parts[1]);

        return $only_seconds + $milliseconds;
    }

    private static function internalTimeToVtt($internal_time): string
    {
        $parts = explode('.', $internal_time); // 1.23
        $whole = $parts[0]; // 1
        $decimal = isset($parts[1]) ? substr($parts[1], 0, 3) : 0; // 23

        return gmdate("H:i:s", floor($whole)) . '.' . str_pad($decimal, 3, '0', STR_PAD_RIGHT);
    }

    private static function removeComments($content): string
    {
        $lines = mb_split("\n", $content);
        $lines = array_map('trim', $lines);
        $new_lines = [];
        $is_comment = false;
        foreach ($lines as $line) {
            if ($is_comment && strlen($line)) {
                continue;
            }
            if (str_starts_with($line, 'NOTE ')) {
                $is_comment = true;
                continue;
            }
            $is_comment = false;
            $new_lines[] = $line;
        }

        return implode("\n", $new_lines);
    }

    private static function getLineParts($line, $colon_count, $timestamp_count)
    {
        $matches = [
            'start' => null,
            'end' => null,
            'text' => null,
        ];
        $timestamps = self::timestampsFromLine($line);

        // there shouldn't be any text before the timestamp
        // if there is text before it, then it is not a timestamp
        $right_timestamp = '';
        if (isset($timestamps['start']) && (substr_count($timestamps['start'], ':') >= $colon_count || substr_count(
                    $timestamps['start'],
                    ';'
                ) >= $colon_count)) {
            $text_before_timestamp = substr($line, 0, strpos($line, $timestamps['start']));
            if (!self::hasText($text_before_timestamp)) {
                // start
                $matches['start'] = $timestamps['start'];
                $right_timestamp = $matches['start'];
                if ($timestamp_count === 2 && isset($timestamps['end']) && (substr_count(
                            $timestamps['end'],
                            ':'
                        ) >= $colon_count || substr_count($timestamps['end'], ';') >= $colon_count)) {
                    // end
                    $matches['end'] = $timestamps['end'];
                    $right_timestamp = $matches['end'];
                }
            }
        }

        // check if there is any text after the timestamp
        if ($right_timestamp) {
            $tmp_parts = explode($right_timestamp, $line); // if start and end timestamp are equals
            $right_text = end($tmp_parts); // take text after the end timestamp
            if (self::hasText($right_text) || self::hasDigit($right_text)) {
                $matches['text'] = trim($right_text);
            }
        } else {
            $matches['text'] = $line;
        }

        return $matches;
    }

    private static function timestampsFromLine(string $line)
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
            $text_before_timestamp = substr($line, 0, strpos($line, $result['start']));
            if (self::hasText($text_before_timestamp)) {
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
