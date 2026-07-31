<?php

declare(strict_types=1);

namespace App\API\System;

use App\Libs\Attributes\Route\Get;
use App\Libs\Enums\Http\Status;
use App\Libs\Events\Queue\EventEnvelopeState;
use App\Libs\Events\Queue\EventTransportInterface;
use App\Model\Events\EventsRepository;
use App\Model\Events\EventStatus;
use Psr\Http\Message\ResponseInterface as iResponse;

final readonly class Stats
{
    public const string URL = '%{api.prefix}/system/stats';

    public function __construct(
        private EventsRepository $events,
        private EventTransportInterface $transport,
    ) {}

    #[Get(pattern: self::URL . '[/]', name: 'system.stats')]
    public function get(): iResponse
    {
        return api_response(
            Status::OK,
            [
                'events' => [
                    'pending' => $this->events->countByStatus(EventStatus::PENDING),
                ],
                'transport' => [
                    'pending' => $this->transport->inspectCount(EventEnvelopeState::PENDING),
                ],
            ],
            headers: ['X-No-AccessLog' => '1'],
        );
    }
}
