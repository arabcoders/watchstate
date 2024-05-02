<?php

declare(strict_types=1);

namespace App\API\Logs;

use App\Libs\Attributes\Route\Get;
use App\Libs\Config;
use App\Libs\DataUtil;
use App\Libs\HTTP_STATUS;
use App\Libs\Stream;
use App\Libs\StreamClosure;
use LimitIterator;
use Nyholm\Psr7\Response;
use Psr\Http\Message\ResponseInterface as iResponse;
use Psr\Http\Message\ServerRequestInterface as iRequest;
use SplFileObject;
use Symfony\Component\Process\Exception\ProcessTimedOutException;
use Symfony\Component\Process\Process;

final class Index
{
    public const string URL = '%{api.prefix}/logs';
    private const int DEFAULT_LIMIT = 1000;
    private int $counter = 1;

    #[Get(self::URL . '[/]', name: 'logs.list')]
    public function logsList(iRequest $request): iResponse
    {
        $path = fixPath(Config::get('tmpDir') . '/logs');

        $list = [];

        $apiUrl = $request->getUri()->withHost('')->withPort(0)->withScheme('');
        parse_str($apiUrl->getquery(), $query);
        $query['stream'] = 1;
        $query = http_build_query($query);

        foreach (glob($path . '/*.*.log') as $file) {
            preg_match('/(\w+)\.(\w+)\.log/i', basename($file), $matches);
            $url = $apiUrl->withPath(parseConfigValue(self::URL . "/" . basename($file)));

            $builder = [
                'type' => $matches[1] ?? '??',
                'date' => $matches[2] ?? '??',
                'size' => filesize($file),
                'modified' => makeDate(filemtime($file))->format('Y-m-d H:i:s T'),
                'urls' => [
                    'self' => (string)$url,
                    'stream' => (string)$url->withQuery($query),
                ],
            ];

            $list[] = $builder;
        }

        return api_response(HTTP_STATUS::HTTP_OK, ['logs' => $list]);
    }

    #[Get(Index::URL . '/{filename}[/]', name: 'logs.view')]
    public function logView(iRequest $request, array $args = []): iResponse
    {
        if (null === ($filename = ag($args, 'filename'))) {
            return api_error('Invalid value for id path parameter.', HTTP_STATUS::HTTP_BAD_REQUEST);
        }

        $path = realpath(fixPath(Config::get('tmpDir') . '/logs'));

        $filePath = realpath($path . '/' . $filename);

        if (false === $filePath) {
            return api_error('File not found.', HTTP_STATUS::HTTP_NOT_FOUND);
        }

        if (false === str_starts_with($filePath, $path)) {
            return api_error('Invalid file path.', HTTP_STATUS::HTTP_BAD_REQUEST);
        }

        $params = DataUtil::fromArray($request->getQueryParams());

        $file = new SplFileObject($filePath, 'r');

        if ($params->get('stream')) {
            return $this->stream($filePath);
        }

        if ($file->getSize() < 1) {
            return api_response(HTTP_STATUS::HTTP_OK);
        }

        $limit = (int)$params->get('limit', self::DEFAULT_LIMIT);
        $limit = $limit < 1 ? self::DEFAULT_LIMIT : $limit;

        $file->seek(PHP_INT_MAX);

        $lastLine = $file->key();

        $it = new LimitIterator($file, max(0, $lastLine - $limit), $lastLine);

        $stream = new Stream(fopen('php://memory', 'w'));

        foreach ($it as $line) {
            $line = trim((string)$line);

            if (empty($line)) {
                continue;
            }

            $stream->write($line . PHP_EOL);
        }

        $stream->rewind();

        return new Response(
            status: HTTP_STATUS::HTTP_OK->value,
            headers: ['Content-Type' => 'text/plain'],
            body: $stream
        );
    }

    private function stream(string $filePath): iResponse
    {
        ini_set('max_execution_time', '3601');

        $callable = function () use ($filePath) {
            ignore_user_abort(true);

            try {
                $cmd = 'exec tail --lines 0 -F ' . escapeshellarg($filePath);

                $process = Process::fromShellCommandline($cmd);
                $process->setTimeout(3600);

                $process->start(callback: function ($type, $data) use ($process) {
                    echo "event: data\n";
                    $data = trim((string)$data);
                    echo implode(
                        PHP_EOL,
                        array_map(
                            function ($data) {
                                if (!is_string($data)) {
                                    return null;
                                }
                                return 'data: ' . trim($data);
                            },
                            (array)preg_split("/\R/", $data)
                        )
                    );
                    echo "\n\n";

                    flush();

                    $this->counter = 3;

                    if (ob_get_length() > 0) {
                        ob_end_flush();
                    }

                    if (connection_aborted()) {
                        $process->stop(1, 9);
                    }
                });

                while ($process->isRunning()) {
                    sleep(1);
                    $this->counter--;

                    if ($this->counter > 1) {
                        continue;
                    }

                    $this->counter = 3;

                    echo "event: ping\n";
                    echo 'data: ' . makeDate() . "\n\n";
                    flush();

                    if (ob_get_length() > 0) {
                        ob_end_flush();
                    }

                    if (connection_aborted()) {
                        $process->stop(1, 9);
                    }
                }
            } catch (ProcessTimedOutException) {
            }

            return '';
        };

        return (new Response(
            status: HTTP_STATUS::HTTP_OK->value,
            headers: [
                'Content-Type' => 'text/event-stream; charset=UTF-8',
                'Cache-Control' => 'no-cache',
                'Connection' => 'keep-alive',
                'X-Accel-Buffering' => 'no',
                'Access-Control-Allow-Origin' => '*',
                'Access-Control-Allow-Credentials' => 'true',
            ],
            body: StreamClosure::create($callable)
        ))->withoutHeader('Content-Length');
    }
}
