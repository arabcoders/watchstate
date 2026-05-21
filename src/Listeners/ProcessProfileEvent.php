<?php

declare(strict_types=1);

namespace App\Listeners;

use App\Libs\Config;
use App\Libs\Enums\Http\Method;
use App\Libs\Enums\Http\Status;
use App\libs\Events\DataEvent;
use App\Libs\Stream;
use App\Model\Events\EventListener;
use Monolog\Level;
use Psr\Log\LoggerInterface as iLogger;
use Symfony\Contracts\HttpClient\Exception\TransportExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface as iHttp;
use Throwable;

#[EventListener(self::NAME)]
final readonly class ProcessProfileEvent
{
    public const string NAME = 'export_profile';

    private array $config;

    /**
     * Class constructor.
     *
     * @param iLogger $logger The logger object.
     * @param iHttp&\App\Libs\Extends\HttpClient $client The http client object.
     */
    public function __construct(
        private iLogger $logger,
        private iHttp $client,
    ) {
        $this->config = Config::get('profiler', []);
    }

    public function __invoke(DataEvent $e): DataEvent
    {
        $profileId = (string) ag($e->getData(), 'meta.id', '??');
        $writer = function (Level $level, string $message, array $context = []) use ($e) {
            $e->addLogEntry($level, $message, $context);
            $this->logger->log($level, $message, $context);
        };

        $e->stopPropagation();

        $hasCollector = null !== ($url = ag($this->config, 'collector'));
        $saveProfile = (bool) ag($this->config, 'save', false);
        if (false === $hasCollector && false === $saveProfile) {
            $writer(Level::Info, "Profile '{profile_id}' export skipped: collector URL is not set and local save is disabled.", [
                'event_name' => 'profile.export.disabled',
                'subsystem' => 'profile',
                'operation' => 'export',
                'outcome' => 'skipped',
                'profile_id' => $profileId,
                'collector_configured' => false,
                'save_enabled' => false,
                'reason' => 'collector_not_configured_and_save_disabled',
            ]);
            return $e;
        }

        $data = json_encode($e->getData());
        if (false === $data) {
            $writer(Level::Error, "Failed to encode profile '{profile_id}'.", [
                'event_name' => 'profile.encode.failed',
                'subsystem' => 'profile',
                'operation' => 'encode',
                'outcome' => 'failed',
                'profile_id' => $profileId,
                'error' => [
                    'message' => json_last_error_msg(),
                ],
            ]);
            return $e;
        }

        if (true === (bool) ag($this->config, 'save', false)) {
            $path = r('{path}/{date}-{uuid}.json', [
                'path' => rtrim(Config::get('profiler.path'), sys_get_temp_dir()),
                'date' => gmdate('YmdHis'),
                'uuid' => ag($e->getData(), 'meta.id', generate_uuid(...)),
            ]);

            try {
                $stream = new Stream($path, 'w');
                $stream->write($data);
                $stream->close();
            } catch (Throwable $ex) {
                $writer(Level::Error, "Failed to save profile '{profile_id}' to '{path}'.", [
                    'event_name' => 'profile.save.failed',
                    'subsystem' => 'profile',
                    'operation' => 'save',
                    'outcome' => 'failed',
                    'profile_id' => $profileId,
                    'path' => $path,
                    ...exception_log($ex),
                ]);
            }
        }

        if (null !== $url) {
            try {
                $response = $this->client->request(Method::POST, $url, ['body' => ['payload' => $data]]);

                $statusCode = $response->getStatusCode();

                if (Status::OK !== Status::tryFrom($statusCode)) {
                    $writer(Level::Error, "Profile collector returned status {http.status_code} for '{profile_id}'.", [
                        'event_name' => 'profile.collector.unexpected_status',
                        'subsystem' => 'profile',
                        'operation' => 'collect',
                        'outcome' => 'failed',
                        'profile_id' => $profileId,
                        'collector' => [
                            'url' => $url,
                        ],
                        'http' => [
                            'status_code' => $statusCode,
                        ],
                    ]);
                    return $e;
                }

                $writer(Level::Notice, "Sent profile '{profile_id}' to collector with status {http.status_code}.", [
                    'event_name' => 'profile.collector.completed',
                    'subsystem' => 'profile',
                    'operation' => 'collect',
                    'outcome' => 'completed',
                    'profile_id' => $profileId,
                    'collector' => [
                        'url' => $url,
                    ],
                    'http' => [
                        'status_code' => $statusCode,
                    ],
                ]);
            } catch (TransportExceptionInterface $ex) {
                $writer(Level::Error, "Failed to send profile '{profile_id}' to collector.", [
                    'event_name' => 'profile.collector.failed',
                    'subsystem' => 'profile',
                    'operation' => 'collect',
                    'outcome' => 'failed',
                    'profile_id' => $profileId,
                    'collector' => [
                        'url' => $url,
                    ],
                    ...exception_log($ex),
                ]);
            }
        }

        return $e;
    }
}
