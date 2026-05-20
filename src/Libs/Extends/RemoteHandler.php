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
        bool $bubble = true,
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
        $structured = ag($record->context, 'structured', []);
        if (false === is_array($structured)) {
            $structured = [];
        }

        if (is_array($structured['server'] ?? null)) {
            $structured['server'] = get_log_server_params($structured['server']);
        }

        try {
            $this->requests[] = $this->client->request(Method::POST->value, $this->url, [
                'timeout' => 6,
                'json' => [
                    'id' => generate_uuid(),
                    'message' => $record->message,
                    'trace' => ag($record->context, 'trace', []),
                    'structured' => $structured,
                    'server' => ag($_SERVER ?? [], ['HTTP_HOST', 'SERVER_NAME'], 'watchstate.cli'),
                    'context' => get_log_server_params($_SERVER ?? []),
                    'raw' => [
                        'datetime' => $record->datetime->format(DATE_ATOM),
                        'level' => strtolower((string) $record->level->getName()),
                        'channel' => $record->channel,
                    ],
                ],
            ]);
        } catch (Throwable $e) {
            syslog(LOG_ERR, sprintf('%s: %s. (%s:%d)', $e::class, $e->getMessage(), $e->getFile(), $e->getLine()));
        }
    }
}
