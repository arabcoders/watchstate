<?php

declare(strict_types=1);

namespace App\Libs\Extends;

use App\Libs\Enums\Http\Method;
use Monolog\Handler\AbstractProcessingHandler;
use Monolog\Level;
use Monolog\LogRecord;
use Symfony\Contracts\HttpClient\HttpClientInterface as iHttp;
use Symfony\Contracts\HttpClient\ResponseInterface;
use Throwable;

final class RemoteHandler extends AbstractProcessingHandler
{
    /**
     * @var array<ResponseInterface>
     */
    private array $requests = [];

    public function __construct(
        private readonly iHttp $client,
        private readonly string $url,
        $level = Level::Debug,
        bool $bubble = true
    ) {
        $this->bubble = $bubble;

        parent::__construct($level, $bubble);
    }

    public function __destruct()
    {
        if (count($this->requests) > 0) {
            foreach ($this->requests as $request) {
                try {
                    $request->getStatusCode();
                } catch (Throwable $e) {
                    syslog(LOG_DEBUG, self::class . ': ' . $e->getMessage());
                }
            }
        }

        parent::__destruct();
    }

    protected function write(LogRecord $record): void
    {
        $server = $_SERVER ?? [];

        foreach ($server as $key => $value) {
            if (is_string($key) && str_starts_with(strtoupper($key), 'WS_')) {
                $server[$key] = '***';
            }
        }

        try {
            $this->requests[] = $this->client->request(Method::POST->value, $this->url, [
                'timeout' => 6,
                'json' => [
                    'id' => generateUUID(),
                    'message' => $record->message,
                    'trace' => ag($record->context, 'trace', []),
                    'structured' => ag($record->context, 'structured', []),
                    'server' => ag($_SERVER ?? [], ['HTTP_HOST', 'SERVER_NAME'], 'watchstate.cli'),
                    'context' => $server,
                    'raw' => $record->toArray(),
                ]
            ]);
        } catch (Throwable $e) {
            syslog(LOG_ERR, sprintf('%s: %s. (%s:%d)', $e::class, $e->getMessage(), $e->getFile(), $e->getLine()));
        }
    }
}
