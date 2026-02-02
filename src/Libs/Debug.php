<?php

declare(strict_types=1);

namespace App\Libs;

use Symfony\Component\VarDumper\Cloner\VarCloner;
use Symfony\Component\VarDumper\Dumper\CliDumper;
use Symfony\Component\VarDumper\VarDumper;

final readonly class Debug
{
    /**
     * Logs items to a log file and/or outputs them to the console or browser.
     *
     * @param array $items An associative array of items to log, where the key is a label and the value is the item to log.
     * @param bool $writeToLog Whether to write the items to a log file. Default is true.
     * @param bool $writeToOut Whether to output the items to the console or browser. Default is true.
     */
    public static function log(array $items, bool $writeToLog = true, bool $writeToOut = true): void
    {
        $trace = debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS);
        $callPath = ($trace[1]['file'] ?? '') . ':' . ($trace[1]['line'] ?? '');

        if ($writeToLog) {
            self::writeToLog($items, $callPath);
        }

        if ($writeToOut) {
            self::writeToOut($items, $callPath);
        }
    }

    private static function writeToLog(array $items, string $callPath): void
    {
        if (null === ($log = Config::get('logger.file.filename'))) {
            return;
        }

        $directory = dirname($log);

        if (false === is_dir($directory)) {
            mkdir(directory: $directory, recursive: true);
        }

        $handle = @fopen((string) Path::make(Config::get('logger.file.filename'))->withName('debug.log'), 'a');

        if (!$handle) {
            return;
        }

        foreach ($items as $key => $item) {
            $output = self::createDump($item) . $callPath;
            fwrite($handle, "{$key} " . $output . PHP_EOL);
        }

        fclose($handle);
    }

    private static function writeToOut(array $items, string $callPath): void
    {
        foreach ($items as $key => $item) {
            if (defined('STDOUT')) {
                fwrite(STDOUT, "===[ {$key} ]===" . PHP_EOL);
                $output = self::createDump($item);
                fwrite(STDOUT, $output);
                fwrite(STDOUT, $callPath . PHP_EOL);
            } else {
                echo
                    sprintf(
                        '<span style=" display:inline-block; color: #fff; font-family: %s; padding: 2px 4px; font-size: 0.8rem; margin-bottom: -12px; background: #0071BC;" >%s (%s)</span>',
                        'Source Code Pro, ui-monospace, SFMono-Regular, Menlo, Monaco, Consolas, Liberation Mono, Courier New, monospace',
                        $key,
                        $callPath,
                    )
                ;
                VarDumper::dump($item);
            }
        }
    }

    private static function createDump(mixed $input): string
    {
        $cloner = new VarCloner();

        $output = '';

        $dumper = new CliDumper(static function ($line, $depth) use (&$output): void {
            if ($depth < 0) {
                return;
            }

            $output .= str_repeat(' ', $depth) . $line . "\n";
        });

        $dumper->setColors(true);

        $dumper->dump($cloner->cloneVar($input));

        return preg_replace(pattern: '/\e](.*)\e]8;;\e/', replacement: '', subject: $output);
    }
}
