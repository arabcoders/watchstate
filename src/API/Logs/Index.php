<?php

declare(strict_types=1);

namespace App\API\Logs;

use App\Libs\Attributes\Route\Get;
use App\Libs\Attributes\Route\Route;
use App\Libs\Config;
use App\Libs\DataUtil;
use App\Libs\Enums\Http\Status;
use App\Libs\Mappers\ImportInterface as iImport;
use App\Libs\Stream;
use App\Libs\StreamedBody;
use App\Libs\Traits\APITraits;
use finfo;
use LimitIterator;
use Psr\Http\Message\ResponseInterface as iResponse;
use Psr\Http\Message\ServerRequestInterface as iRequest;
use Psr\Log\LoggerInterface as iLogger;
use Random\RandomException;
use SplFileObject;
use Symfony\Component\Process\Exception\ProcessTimedOutException;
use Symfony\Component\Process\Process;

final class Index
{
    use APITraits;

    public const string URL = '%{api.prefix}/logs';
    public const string URL_FILE = '%{api.prefix}/log';
    private const int MAX_LIMIT = 100;

    private int $counter = 1;
    private array $users = [];
    private array $logsDir = [];

    public function __construct(iImport $mapper, iLogger $logger)
    {
        $this->users = array_keys(get_users_context(mapper: $mapper, logger: $logger));
        $this->logsDir = [
            [
                'path' => fix_path(Config::get('tmpDir') . '/logs'),
                'type' => '*.jsonl',
            ],
            [
                'path' => fix_path(Config::get('tmpDir') . '/webhooks'),
                'type' => '*.json',
            ],
            [
                'path' => fix_path(Config::get('tmpDir') . '/debug'),
                'type' => '*.json',
            ],
        ];
    }

    #[Get(self::URL . '[/]', name: 'logs')]
    public function logsList(iRequest $request): iResponse
    {
        $list = [];

        $apiUrl = $request->getUri()->withHost('')->withPort(0)->withScheme('');
        parse_str($apiUrl->getquery(), $query);
        $query['stream'] = 1;
        $query = http_build_query($query);

        foreach ($this->logsDir as $pathInfo) {
            $path = ag($pathInfo, 'path');
            $type = ag($pathInfo, 'type', '*.*.log');
            foreach (glob($path . '/' . $type) as $file) {
                preg_match('/(\w+)\.(.+?)\.(jsonl|json)$/i', basename($file), $matches);
                $date = $matches[2] ?? null;
                if (null !== $date && !is_numeric($date)) {
                    $date = null;
                }

                $builder = [
                    'filename' => basename($file),
                    'type' => $matches[1] ?? '??',
                    'date' => $date,
                    'size' => filesize($file),
                    'modified' => make_date(filemtime($file)),
                ];

                $list[] = $builder;
            }
        }

        return api_response(Status::OK, $list);
    }

    /**
     * @throws RandomException
     */
    #[Get(Index::URL . '/recent[/]', name: 'logs.recent')]
    public function recent(iRequest $request): iResponse
    {
        $path = $this->logsDir[0]['path'] ?? null;
        $type = $this->logsDir[0]['type'] ?? null;
        if (null === $path || null === $type) {
            return api_error('Log path not configured.', Status::INTERNAL_SERVER_ERROR);
        }

        $list = [];

        $today = make_date()->format('Ymd');

        $params = DataUtil::fromArray($request->getQueryParams());
        $limit = (int) $params->get('limit', 50);
        $limit = $limit < 1 ? 50 : $limit;

        foreach (glob($path . '/' . $type) as $file) {
            preg_match('/(\w+)\.(\w+)\.jsonl$/i', basename($file), $matches);

            $logDate = $matches[2] ?? null;

            if (!$logDate || $logDate !== $today) {
                continue;
            }

            $builder = [
                'filename' => basename($file),
                'type' => $matches[1] ?? '??',
                'date' => $matches[2] ?? '??',
                'size' => filesize($file),
                'modified' => make_date(filemtime($file)),
                'lines' => [],
            ];

            $file = new SplFileObject($file, 'r');

            if ($file->getSize() < 1) {
                continue;
            }

            $file->seek(PHP_INT_MAX);
            $lastLine = $file->key();
            $it = new LimitIterator($file, max(0, $lastLine - $limit), $lastLine);
            foreach ($it as $line) {
                $line = trim((string) $line);
                if (empty($line)) {
                    continue;
                }

                $entry = self::decodeJsonlLine($line);

                if (null !== $entry) {
                    $builder['lines'][] = $entry;
                }
            }

            $list[] = $builder;
        }

        return api_response(Status::OK, $list, headers: [
            'X-No-AccessLog' => '1',
        ]);
    }

    /**
     * @throws RandomException
     */
    #[Route(['GET', 'DELETE'], Index::URL_FILE . '/{filename}[/]', name: 'logs.view')]
    public function logView(iRequest $request, array $args = []): iResponse
    {
        if (null === ($filename = ag($args, 'filename'))) {
            return api_error('Invalid value for filename path parameter.', Status::BAD_REQUEST);
        }

        if (null === ($filePath = $this->getFile($filename))) {
            return api_error('File not found.', Status::NOT_FOUND);
        }

        if ('DELETE' === $request->getMethod()) {
            unlink($filePath);
            return api_response(Status::OK);
        }

        $params = DataUtil::fromArray($request->getQueryParams());

        $file = new SplFileObject($filePath, 'r');

        if (true === (bool) $params->get('download')) {
            return $this->download($filePath);
        }
        if ($params->get('stream')) {
            return $this->stream($filePath);
        }

        if (0 === ($offset = (int) $params->get('offset', 0)) || $offset < 0) {
            $offset = self::MAX_LIMIT;
        }

        $ext = pathinfo($filePath, PATHINFO_EXTENSION);
        $parser = 'json' === $ext ? 'json' : 'jsonl';

        if ($file->getSize() < 1) {
            return api_response(Status::OK, [
                'filename' => basename($filePath),
                'offset' => $offset,
                'next' => null,
                'max' => 0,
                'lines' => [],
                'parser' => $parser,
            ]);
        }

        $file->seek(PHP_INT_MAX);
        $lastLine = 'json' === $ext ? 0 : $file->key();

        if ($offset === self::MAX_LIMIT && self::MAX_LIMIT >= $lastLine) {
            $offset = $lastLine;
        }

        $data = [
            'filename' => basename($filePath),
            'offset' => $offset,
            'next' => null,
            'max' => $lastLine,
            'lines' => [],
            'parser' => $parser,
        ];

        if ($offset <= $lastLine) {
            if ('json' === $ext) {
                $content = @file_get_contents($filePath);

                if (false !== $content) {
                    $entry = json_decode($content, true, 512, JSON_INVALID_UTF8_IGNORE);

                    if (is_array($entry)) {
                        $data['lines'][] = $entry;
                    }
                }
            } else {
                $start = max(0, $lastLine - $offset);
                $it = new LimitIterator($file, $start, self::MAX_LIMIT);

                foreach ($it as $line) {
                    $line = trim((string) $line);

                    if (empty($line)) {
                        continue;
                    }

                    $entry = self::decodeJsonlLine($line);

                    if (null !== $entry) {
                        $data['lines'][] = $entry;
                    }
                }
            }

            $hasMore = $lastLine > $offset;
            $data['next'] = $hasMore ? min($offset + self::MAX_LIMIT, $lastLine) : null;
        }

        return api_response(Status::OK, $data, headers: ['X-No-AccessLog' => '1']);
    }

    private function download(string $filePath): iResponse
    {
        $mime = new finfo(FILEINFO_MIME_TYPE)->file($filePath);

        return api_response(Status::OK, Stream::make($filePath, 'r'), headers: [
            'Content-Type' => false === $mime ? 'application/octet-stream' : $mime,
            'Content-Length' => filesize($filePath),
        ]);
    }

    private function stream(string $filePath): iResponse
    {
        ini_set('max_execution_time', '3601');

        $callable = function () use ($filePath) {
            ignore_user_abort(true);

            try {
                $cmd = 'exec tail -n 0 -F ' . escapeshellarg($filePath);

                $process = Process::fromShellCommandline($cmd);
                $process->setTimeout(3600);

                $process->start(callback: function ($type, $data) use ($process) {
                    echo "event: data\n";
                    $data = trim((string) $data);
                    echo
                        implode(
                            PHP_EOL,
                            array_map(
                                static function ($line) {
                                    if (!is_string($line)) {
                                        return null;
                                    }

                                    $line = trim($line);

                                    if ('' === $line) {
                                        return null;
                                    }

                                    $entry = self::decodeJsonlLine($line);

                                    if (null !== $entry) {
                                        return 'data: ' . json_encode($entry);
                                    }

                                    return null;
                                },
                                (array) preg_split("/\R/", $data),
                            ),
                        )
                    ;
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

                    echo ': ping ' . make_date() . "\n\n";
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

        return api_response(Status::OK, StreamedBody::create($callable), headers: [
            'Content-Type' => 'text/event-stream; charset=UTF-8',
            'Cache-Control' => 'no-cache',
            'Connection' => 'keep-alive',
            'X-Accel-Buffering' => 'no',
        ]);
    }

    private function getFile(string $file): ?string
    {
        foreach ($this->logsDir as $pathInfo) {
            $path = realpath(ag($pathInfo, 'path'));

            $filePath = realpath($path . '/' . $file);

            if (false === $filePath) {
                continue;
            }

            if (false === str_starts_with($filePath, $path)) {
                continue;
            }
            return $filePath;
        }
        return null;
    }

    /**
     * Decode a JSONL line into a structured log entry.
     *
     * @return array<string, mixed>|null
     */
    public static function decodeJsonlLine(string $line): ?array
    {
        $payload = json_decode($line, true, 512, JSON_INVALID_UTF8_IGNORE);

        if (!is_array($payload)) {
            return null;
        }

        foreach (['id', 'datetime', 'level', 'logger'] as $required) {
            if (!isset($payload[$required]) || '' === trim((string) $payload[$required])) {
                return null;
            }
        }

        if (false === array_key_exists('message', $payload)) {
            return null;
        }

        return $payload;
    }
}
