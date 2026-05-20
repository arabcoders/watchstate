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
                'type' => ['*.*.jsonl'],
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
            foreach ((array) ag($pathInfo, 'type', '*.*.jsonl') as $type) {
                $files = glob($path . '/' . $type);
                if (false === $files) {
                    continue;
                }

                foreach ($files as $file) {
                    preg_match('/(\w+)\.(.+?)\.(jsonl|json)/i', basename($file), $matches);
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
        $types = $this->logsDir[0]['type'] ?? null;
        if (null === $path || null === $types) {
            return api_error('Log path not configured.', Status::INTERNAL_SERVER_ERROR);
        }

        $list = [];

        $today = make_date()->format('Ymd');

        $params = DataUtil::fromArray($request->getQueryParams());
        $limit = (int) $params->get('limit', 50);
        $limit = $limit < 1 ? 50 : $limit;

        foreach ((array) $types as $type) {
            $files = glob($path . '/' . $type);
            if (false === $files) {
                continue;
            }

            foreach ($files as $file) {
                preg_match('/(\w+)\.(\w+)\.(jsonl)/i', basename($file), $matches);

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

                if ($file->getSize() > 1) {
                    $file->seek(PHP_INT_MAX);
                    $lastLine = $file->key();
                    $it = new LimitIterator($file, max(0, $lastLine - $limit), $lastLine);
                    foreach ($it as $line) {
                        $line = trim((string) $line);
                        if (empty($line)) {
                            continue;
                        }

                        $builder['lines'][] = $line;
                    }
                }

                $list[] = $builder;
            }
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

        if ($file->getSize() < 1) {
            return api_response(Status::OK, [
                'filename' => basename($filePath),
                'offset' => $offset,
                'next' => null,
                'max' => 0,
                'lines' => [],
            ]);
        }

        $contentType = pathinfo($filePath, PATHINFO_EXTENSION);

        $file->seek(PHP_INT_MAX);
        $lastLine = 'json' === $contentType ? 0 : $file->key();

        if ($offset === self::MAX_LIMIT && self::MAX_LIMIT >= $lastLine) {
            $offset = $lastLine;
        }

        $data = [
            'filename' => basename($filePath),
            'offset' => $offset,
            'next' => null,
            'max' => $lastLine,
            'lines' => [],
            'type' => 'json' === $contentType ? 'json' : 'log',
        ];

        if ($offset <= $lastLine) {
            $start = max(0, $lastLine - $offset);
            $it = new LimitIterator($file, $start, 'json' === $contentType ? PHP_INT_MAX : self::MAX_LIMIT);

            foreach ($it as $line) {
                $line = trim((string) $line);

                if ('' === $line) {
                    continue;
                }

                $data['lines'][] = $line;
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
                                static function ($data) {
                                    if (!is_string($data)) {
                                        return null;
                                    }

                                    $data = trim($data);
                                    if ('' === $data) {
                                        return null;
                                    }

                                    return 'data: ' . json_encode(['data' => $data]);
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
     * Format log line.
     *
     * @param mixed $line
     * @param array $users
     *
     * @return array
     * @throws RandomException
     */
    public static function formatLog(mixed $line, array $users = [], bool $allowStructured = true): array
    {
        if (true === $allowStructured && is_array($line)) {
            if (true === self::isJsonlLog($line)) {
                return self::formatJsonlLog($line, $users);
            }

            if (1 === (int) ag($line, 'schema', 0)) {
                return self::formatSchemaLog($line, $users);
            }
        }

        if (!is_string($line)) {
            $encoded = json_encode($line);
            $line = false === $encoded ? '' : $encoded;
        }

        $line ??= '';

        if (empty($line)) {
            return [
                'id' => md5((string) (hrtime(true) + random_int(1, 10_000))),
                'item_id' => null,
                'event_id' => null,
                'user' => null,
                'backend' => null,
                'date' => null,
                'datetime' => null,
                'level' => null,
                'logger' => null,
                'text' => $line,
                'message' => null,
                'fields' => [],
            ];
        }

        $json = json_decode($line, true);
        if (true === $allowStructured && is_array($json)) {
            if (true === self::isJsonlLog($json)) {
                return self::formatJsonlLog($json, $users);
            }

            if (1 === (int) ag($json, 'schema', 0)) {
                return self::formatSchemaLog($json, $users);
            }
        }

        return self::formatLegacyLog($line, $users);
    }

    private static function isJsonlLog(array $line): bool
    {
        foreach (['id', 'datetime', 'level', 'message'] as $key) {
            if (false === array_key_exists($key, $line)) {
                return false;
            }
        }

        return array_key_exists('logger', $line) || array_key_exists('channel', $line);
    }

    /**
     * @throws RandomException
     */
    private static function formatJsonlLog(array $line, array $users): array
    {
        $fields = ag($line, 'fields', []);
        if (false === is_array($fields)) {
            $fields = [];
        }

        $text = (string) ag($line, 'message', '');
        $legacy = self::formatLegacyLog($text, $users);

        $eventId = ag($fields, ['event_id', 'event.id', 'attributes.event.id'], ag($legacy, 'event_id'));
        $itemId = ag($fields, ['item_id', 'item.id', 'attributes.item.id'], ag($legacy, 'item_id'));
        $user = ag($fields, ['user', 'user.name', 'attributes.user.name'], ag($legacy, 'user'));
        $backend = ag($fields, ['backend', 'backend.name', 'attributes.backend.name', 'via'], ag($legacy, 'backend'));

        $fallbackId = static fn() => md5((string) json_encode($line) . (hrtime(true) + random_int(1, 10_000)));

        return [
            'id' => (string) ag($line, 'id', $fallbackId),
            'item_id' => null === $itemId ? null : (string) $itemId,
            'event_id' => null === $eventId ? null : (string) $eventId,
            'user' => null === $user ? null : (string) $user,
            'backend' => null === $backend ? null : (string) $backend,
            'date' => ag($line, 'datetime'),
            'datetime' => ag($line, 'datetime'),
            'level' => ag($line, 'level'),
            'logger' => ag($line, ['logger', 'channel']),
            'text' => $text,
            'message' => $text,
            'fields' => $fields,
            'source' => ag($line, 'source', []),
        ];
    }

    /**
     * @throws RandomException
     */
    private static function formatSchemaLog(array $line, array $users): array
    {
        $context = ag($line, 'context', []);
        if (false === is_array($context)) {
            $context = [];
        }

        $extras = ag($line, 'extras', []);
        if (false === is_array($extras)) {
            $extras = [];
        }

        $text = (string) ag($line, 'text', ag($line, 'message', ''));
        $legacy = self::formatLegacyLog($text, $users);
        $fallbackId = static fn() => md5((string) json_encode($line) . (hrtime(true) + random_int(1, 10_000)));
        $itemId = ag($extras, 'item_id', ag($legacy, 'item_id'));
        $eventId = ag($extras, 'event_id', ag($legacy, 'event_id'));
        $user = ag($extras, 'user', ag($legacy, 'user'));
        $backend = ag($extras, 'backend', ag($legacy, 'backend'));

        return [
            'id' => (string) ag($line, 'id', $fallbackId),
            'item_id' => null === $itemId ? null : (string) $itemId,
            'event_id' => null === $eventId ? null : (string) $eventId,
            'user' => null === $user ? null : (string) $user,
            'backend' => null === $backend ? null : (string) $backend,
            'date' => ag($line, 'datetime'),
            'datetime' => ag($line, 'datetime'),
            'level' => ag($line, 'level'),
            'logger' => ag($line, ['logger', 'channel']),
            'text' => $text,
            'message' => ag($line, 'message'),
            'fields' => array_replace($context, $extras),
            'source' => ag($extras, 'source'),
        ];
    }

    /**
     * @throws RandomException
     */
    private static function formatLegacyLog(string $line, array $users): array
    {
        $dateRegex = '/^\[([0-9]{4}-[0-9]{2}-[0-9]{2}T[0-9]{2}:[0-9]{2}:[0-9]{2}(?:\.[0-9]+)?[+-][0-9]{2}:[0-9]{2})]/i';
        $eventRegex = '/\[event:(?<event_id>[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12})]\s*/i';
        $levelRegex = '/^(?:[a-z0-9_.-]+\.)?(?<level>EMERGENCY|ALERT|CRITICAL|ERROR|WARNING|NOTICE|INFO|DEBUG):\s*/i';

        $dateMatch = preg_match($dateRegex, $line, $matches);
        $idMatch = preg_match("/'#(?P<item_id>\d+):/", $line, $idMatches);
        $eventMatch = preg_match($eventRegex, $line, $eventMatches);
        $identMatch = preg_match("/'((?P<client>\w+):\s)?(?P<user>\w+)@(?P<backend>\w+)'/i", $line, $identMatches);
        $text = 1 === $dateMatch ? trim(preg_replace($dateRegex, '', $line)) : $line;
        $levelMatch = preg_match($levelRegex, $text, $levelMatches);

        if (1 === $eventMatch) {
            $text = trim(preg_replace($eventRegex, '', $text, 1));
        }

        $logLine = [
            'id' => md5($line . (hrtime(true) + random_int(1, 10_000))),
            'item_id' => null,
            'event_id' => 1 === $eventMatch ? $eventMatches['event_id'] : null,
            'user' => null,
            'backend' => null,
            'date' => 1 === $dateMatch ? $matches[1] : null,
            'datetime' => 1 === $dateMatch ? $matches[1] : null,
            'level' => 1 === $levelMatch ? strtolower($levelMatches['level']) : null,
            'logger' => null,
            'text' => $text,
            'message' => null,
            'fields' => [],
        ];

        if (1 === $idMatch) {
            $logLine['item_id'] = $idMatches['item_id'];
        }

        if (1 === $identMatch && ([] === $users || in_array($identMatches['user'], $users, true))) {
            $logLine['user'] = $identMatches['user'];
            $logLine['backend'] = $identMatches['backend'];
        }

        return $logLine;
    }
}
