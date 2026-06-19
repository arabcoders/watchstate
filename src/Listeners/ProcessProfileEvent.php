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
        $writer = function (Level $level, string $message, array $context = []) use ($e) {
            $e->addLog($level, $message, $context);
            $this->logger->log($level, $message, $context);
        };

        $e->stopPropagation();

        $hasCollector = null !== ($url = ag($this->config, 'collector'));
        $saveProfile = (bool) ag($this->config, 'save', false);
        if (false === $hasCollector && false === $saveProfile) {
            $writer(Level::Info, 'No profile collector url was set and save is disabled.');
            return $e;
        }

        $data = json_encode($e->getData());
        if (false === $data) {
            $writer(Level::Error, 'Failed to encode profile data.');
            return $e;
        }

        if (true === (bool) ag($this->config, 'save', false)) {
            try {
                $stream = new Stream(r('{path}/{date}-{uuid}.json', [
                    'path' => rtrim(Config::get('profiler.path'), sys_get_temp_dir()),
                    'date' => gmdate('YmdHis'),
                    'uuid' => ag($e->getData(), 'meta.id', generate_uuid(...)),
                ]), 'w');
                $stream->write($data);
                $stream->close();
            } catch (Throwable $e) {
                $writer(Level::Error, 'Failed to save profile data. {exception.message}', exception_log($e));
            }
        }

        if (true === $hasCollector && null !== $url) {
            try {
                $response = $this->client->request(Method::POST, $url, ['body' => ['payload' => $data]]);

                $statusCode = $response->getStatusCode();

                if (Status::OK !== Status::tryFrom($statusCode)) {
                    $writer(Level::Error, "Failed to process profile '{id}'. Status: '{response.status_code}'.", [
                        'id' => ag($e->getData(), 'meta.id', '??'),
                        'response' => ['status_code' => $statusCode],
                    ]);
                    return $e;
                }

                $writer(Level::Notice, "Successfully Processed '{id}'.", [
                    'id' => ag($e->getData(), 'meta.id', '??'),
                ]);
            } catch (TransportExceptionInterface $e) {
                $writer(Level::Error, 'Error sending profile data to collector. {exception.message}', exception_log($e));
            }
        }

        return $e;
    }
}
