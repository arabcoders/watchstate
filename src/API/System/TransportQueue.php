<?php

declare(strict_types=1);

namespace App\API\System;

use App\Libs\Attributes\Route\Get;
use App\Libs\DataUtil;
use App\Libs\Enums\Http\Status;
use App\Libs\Events\Queue\EventEnvelope;
use App\Libs\Events\Queue\EventEnvelopeState;
use App\Libs\Events\Queue\EventTransportInterface;
use Psr\Http\Message\ResponseInterface as iResponse;
use Psr\Http\Message\ServerRequestInterface as iRequest;

final readonly class TransportQueue
{
    public const string URL = '%{api.prefix}/system/transport/queue';
    public const int PERPAGE = 25;

    public function __construct(
        private EventTransportInterface $transport,
    ) {}

    #[Get(pattern: self::URL . '[/]', name: 'system.transport.queue.list')]
    public function list(iRequest $request): iResponse
    {
        $params = DataUtil::fromRequest($request, true);
        [$page, $perpage] = get_pagination($request, 1, self::PERPAGE);
        $page = max(1, $page);
        $perpage = max(1, min(100, $perpage));
        $state = strtolower(trim((string) $params->get('state', '')));
        $filter = trim((string) $params->get('filter', ''));
        $states = $this->transport->inspectStates();
        $stateEnum = '' === $state ? null : EventEnvelopeState::tryFrom($state);

        if ('' !== $state && (null === $stateEnum || false === in_array($stateEnum, $states, true))) {
            return api_error("Invalid transport state '{$state}'.", Status::BAD_REQUEST);
        }

        $total = $this->transport->inspectCount($stateEnum, $filter);
        $page = min($page, max(1, (int) ceil($total / $perpage)));
        $start = $perpage * ($page - 1);

        return api_response(
            Status::OK,
            [
                'items' => array_map(
                    fn(EventEnvelope $envelope): array => $this->formatEnvelope($envelope, false),
                    $this->transport->inspect($perpage, $start, $stateEnum, $filter),
                ),
                'paging' => [
                    'page' => $page,
                    'total' => $total,
                    'perpage' => $perpage,
                    'next' => $page < (int) ceil($total / $perpage) ? $page + 1 : null,
                    'previous' => $page > 1 ? $page - 1 : null,
                ],
                'filter' => [
                    'state' => null !== $stateEnum ? $stateEnum->value : '',
                    'filter' => $filter,
                ],
                'states' => array_map(static fn(EventEnvelopeState $item): string => $item->value, $states),
            ],
            headers: ['X-No-AccessLog' => '1'],
        );
    }

    #[Get(pattern: self::URL . '/{id}[/]', name: 'system.transport.queue.view')]
    public function view(string $id): iResponse
    {
        $id = trim($id);
        if ('' === $id) {
            return api_error('Invalid transport envelope id.', Status::BAD_REQUEST);
        }

        $envelope = $this->transport->inspectOne($id);
        if (null === $envelope) {
            return api_error('Transport envelope not found.', Status::NOT_FOUND);
        }

        return api_response(Status::OK, $this->formatEnvelope($envelope, true), headers: ['X-No-AccessLog' => '1']);
    }

    /**
     * @return array<string,mixed>
     */
    private function formatEnvelope(EventEnvelope $envelope, bool $includePayload): array
    {
        $formatted = [
            'id' => $envelope->id,
            'event' => $envelope->event,
            'state' => $envelope->state,
            'created_at' => $envelope->createdAt,
        ];

        if ($includePayload) {
            $formatted['data'] = $envelope->data;
            $formatted['options'] = $envelope->opts;
        }

        return $formatted;
    }
}
