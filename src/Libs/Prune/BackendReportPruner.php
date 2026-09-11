<?php

declare(strict_types=1);

namespace App\Libs\Prune;

use App\Libs\Attributes\Cli\Prune;
use App\Libs\Attributes\DI\ForModel;
use App\Libs\BackendReportGenerator;
use App\Libs\Config;
use App\Models\BackendReport;
use arabcoders\database\Orm\EntityRepository;
use arabcoders\database\Query\Condition;
use Psr\Log\LoggerInterface as iLogger;

#[Prune(name: 'Backend Reports', cron: '30 5 * * *', desc: 'Remove old backend reports.')]
final class BackendReportPruner
{
    /**
     * @param iLogger $logger Logger instance.
     * @param EntityRepository<BackendReport> $reports Backend report repository.
     */
    public function __construct(
        private readonly iLogger $logger,
        #[ForModel(BackendReport::class)]
        private readonly EntityRepository $reports,
    ) {}

    /**
     * Prune old backend reports.
     *
     * @param bool $execute Whether to delete the reports.
     */
    public function __invoke(bool $execute): void
    {
        $keep = max(1, (int) Config::get('backend_report.keep', 3));
        $reports = $this->reports->findBy(
            limit: null,
            orderBy: ['generated_at' => 'DESC', 'id' => 'DESC'],
            columns: ['id', 'identity', 'status'],
        );
        $seen = [];
        $ids = [];

        foreach ($reports as $report) {
            if (
                false === in_array(
                    $report->status,
                    [
                        BackendReportGenerator::STATUS_COMPLETED,
                        BackendReportGenerator::STATUS_FAILED,
                    ],
                    true,
                )
            ) {
                continue;
            }

            $key = $report->identity . ':' . $report->status;
            $seen[$key] = (int) ($seen[$key] ?? 0) + 1;
            if ($seen[$key] > $keep && null !== $report->id) {
                $ids[] = $report->id;
            }
        }

        if ([] === $ids) {
            $this->logger->debug('No old backend reports found.', [
                'operation' => 'prune.backend_report',
                'error' => 'no_expired_reports',
                'keep' => $keep,
            ]);
            return;
        }

        if (true === $execute) {
            $this->reports->deleteWhere(Condition::in('id', $ids));
        }

        $this->logger->info("{action} '{count}' old backend reports.", [
            'action' => true === $execute ? 'Pruned' : 'Found',
            'operation' => 'prune.backend_report',
            'count' => count($ids),
            'keep' => $keep,
        ]);
    }
}
