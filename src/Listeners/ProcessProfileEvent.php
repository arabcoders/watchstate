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
     * @param iHttp $client The http client object.
     */
    public function __construct(private iLogger $logger, private iHttp $client)
    {
        $this->config = Config::get('profiler', []);
    }

    public function __invoke(DataEvent $e): DataEvent
    {
        $writer = function (Level $level, string $message, array $context = []) use ($e) {
            $e->addLog($level->getName() . ': ' . r($message, $context));
            $this->logger->log($level, $message, $context);
        };

        $e->stopPropagation();

        $hasCollector = null !== ($url = ag($this->config, 'collector'));
        $saveProfile = (bool)ag($this->config, 'save', false);
        if (false === $hasCollector && false === $saveProfile) {
            $writer(Level::Info, 'No profile collector url was set and save is disabled.');
            return $e;
        }

        $data = json_encode($e->getData());
        if (false === $data) {
            $writer(Level::Error, 'Failed to encode profile data.');
            return $e;
        }

        if (true === (bool)ag($this->config, 'save', false)) {
            try {
                $stream = new Stream(r('{path}/{date}-{uuid}.json', [
                    'path' => rtrim(Config::get('profiler.path'), sys_get_temp_dir()),
                    'date' => gmdate('YmdHis'),
                    'uuid' => ag($e->getData(), 'meta.id', fn() => generateUUID()),
                ]), 'w');
                $stream->write($data);
                $stream->close();
            } catch (Throwable $e) {
                $writer(Level::Error, 'Failed to save profile data. {message}', [
                    'message' => $e->getMessage(),
                    'exception' => $e
                ]);
            }
        }

        if (null !== $url) {
            try {
                $response = $this->client->request(Method::POST->value, $url, ['body' => ['payload' => $data]]);

                $statusCode = $response->getStatusCode();

                if (Status::OK !== Status::tryFrom($statusCode)) {
                    $this->logger->error("Failed to process profile '{id}'. Status: '{status}'.", [
                        'id' => ag($e->getData(), 'meta.id', '??'),
                        'status' => $statusCode,
                    ]);
                    return $e;
                }

                $this->logger->notice("Successfully Processed '{id}'.", [
                    'id' => ag($e->getData(), 'meta.id', '??'),
                ]);
            } catch (TransportExceptionInterface $e) {
                $writer(Level::Error, 'Error sending profile data to collector. {message}', [
                    'message' => $e->getMessage(),
                    'exception' => $e
                ]);
            }
        }

        return $e;
    }

}
