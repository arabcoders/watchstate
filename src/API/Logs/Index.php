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
use SplFileObject;
use Symfony\Component\Process\Exception\ProcessTimedOutException;
use Symfony\Component\Process\Process;

final class Index
{
    use APITraits;

    public const string URL = '%{api.prefix}/logs';
    public const string URL_FILE = '%{api.prefix}/log';
    private const int DEFAULT_LIMIT = 1000;
    private int $counter = 1;

    #[Get(self::URL . '[/]', name: 'logs')]
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

            $builder = [
                'filename' => basename($file),
                'type' => $matches[1] ?? '??',
                'date' => $matches[2] ?? '??',
                'size' => filesize($file),
                'modified' => makeDate(filemtime($file)),
            ];

            $list[] = $builder;
        }

        return api_response(Status::OK, $list);
    }

    #[Get(Index::URL . '/recent[/]', name: 'logs.recent')]
    public function recent(iRequest $request, iImport $mapper, iLogger $logger): iResponse
    {
        $users = array_keys(getUsersContext(mapper: $mapper, logger: $logger));

        $path = fixPath(Config::get('tmpDir') . '/logs');

        $list = [];

        $today = makeDate()->format('Ymd');

        $params = DataUtil::fromArray($request->getQueryParams());
        $limit = (int)$params->get('limit', 50);
        $limit = $limit < 1 ? 50 : $limit;
        $dateRegex = '/^\[([0-9]{4}-[0-9]{2}-[0-9]{2}T[0-9]{2}:[0-9]{2}:[0-9]{2}(?:\.[0-9]+)?[+-][0-9]{2}:[0-9]{2})]/i';
        $identRegex = "/\'(?P<user>\w+)\@(?P<backend>\w+)\'/i";
        $idRegex = "/\'#(?P<id>\d+)\:/";

        foreach (glob($path . '/*.*.log') as $file) {
            preg_match('/(\w+)\.(\w+)\.log/i', basename($file), $matches);

            $logDate = $matches[2] ?? null;

            if (!$logDate || $logDate !== $today) {
                continue;
            }

            $builder = [
                'filename' => basename($file),
                'type' => $matches[1] ?? '??',
                'date' => $matches[2] ?? '??',
                'size' => filesize($file),
                'modified' => makeDate(filemtime($file)),
                'lines' => [],
            ];

            $file = new SplFileObject($file, 'r');

            if ($file->getSize() > 1) {
                $file->seek(PHP_INT_MAX);
                $lastLine = $file->key();
                $it = new LimitIterator($file, max(0, $lastLine - $limit), $lastLine);
                foreach ($it as $line) {
                    $line = trim((string)$line);
                    if (empty($line)) {
                        continue;
                    }

                    $match = preg_match($dateRegex, $line, $matches);
                    $idMatch = preg_match($idRegex, $line, $idMatches);
                    $identMatch = preg_match($identRegex, $line, $identMatches);

                    $logLine = [
                        'id' => null,
                        'user' => null,
                        'backend' => null,
                        'date' => 1 === $match ? $matches[1] : null,
                        'text' => 1 === $match ? trim(preg_replace($dateRegex, '', $line)) : $line,
                    ];

                    if (1 === $idMatch) {
                        $logLine['id'] = $idMatches['id'];
                    }

                    if (1 === $identMatch && in_array($identMatches['user'], $users, true)) {
                        $logLine['user'] = $identMatches['user'];
                        $logLine['backend'] = $identMatches['backend'];
                    }

                    $builder['lines'][] = $logLine;
                }
            }

            $list[] = $builder;
        }

        return api_response(Status::OK, $list);
    }

    #[Route(['GET', 'DELETE'], Index::URL_FILE . '/{filename}[/]', name: 'logs.view')]
    public function logView(iRequest $request, array $args = []): iResponse
    {
        if (null === ($filename = ag($args, 'filename'))) {
            return api_error('Invalid value for filename path parameter.', Status::BAD_REQUEST);
        }

        $path = realpath(fixPath(Config::get('tmpDir') . '/logs'));

        $filePath = realpath($path . '/' . $filename);

        if (false === $filePath) {
            return api_error('File not found.', Status::NOT_FOUND);
        }

        if (false === str_starts_with($filePath, $path)) {
            return api_error('Invalid file path.', Status::BAD_REQUEST);
        }

        if ('DELETE' === $request->getMethod()) {
            unlink($filePath);
            return api_response(Status::OK);
        }

        $params = DataUtil::fromArray($request->getQueryParams());

        $file = new SplFileObject($filePath, 'r');

        if (true === (bool)$params->get('download')) {
            return $this->download($filePath);
        }
        if ($params->get('stream')) {
            return $this->stream($filePath);
        }

        if ($file->getSize() < 1) {
            return api_response(Status::OK);
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

        return api_response(Status::OK, $stream, headers: [
            'Content-Type' => 'text/plain',
            'X-No-AccessLog' => '1'
        ]);
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

        return api_response(Status::OK, StreamedBody::create($callable), headers: [
            'Content-Type' => 'text/event-stream; charset=UTF-8',
            'Cache-Control' => 'no-cache',
            'Connection' => 'keep-alive',
            'X-Accel-Buffering' => 'no',
        ]);
    }
}
