<?php

declare(strict_types=1);

namespace App\API;

use App\Libs\Attributes\Route\Get;
use App\Libs\Config;
use App\Libs\Enums\Http\Status;
use Cron\CronExpression;
use DateTimeZone;
use Psr\Http\Message\ResponseInterface as iResponse;
use Throwable;

final class Prune
{
    public const string URL = '%{api.prefix}/prune';

    #[Get(self::URL . '[/]', name: 'prune.index')]
    public function pruneIndex(): iResponse
    {
        $pruners = [];
        $displayTZ = new DateTimeZone((string) Config::get('tz', 'UTC'));

        foreach (discover_pruners() as $pruner) {
            $cron = ag($pruner, 'cron');
            $nextRun = null;

            if (null !== $cron) {
                try {
                    $nextRun = new CronExpression((string) $cron)
                        ->getNextRunDate('now')
                        ->setTimezone($displayTZ)
                        ->format('Y-m-d\TH:i:sP');
                } catch (Throwable) {
                }
            }

            $pruners[] = [
                'name' => ag($pruner, 'name'),
                'display_name' => ag($pruner, 'display_name'),
                'description' => ag($pruner, 'desc'),
                'cron' => $cron,
                'enabled' => (bool) ag($pruner, 'enabled', true),
                'next_run' => $nextRun,
            ];
        }

        return api_response(Status::OK, [
            'pruners' => $pruners,
        ]);
    }
}
