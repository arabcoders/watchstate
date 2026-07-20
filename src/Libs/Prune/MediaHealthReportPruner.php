<?php

declare(strict_types=1);

namespace App\Libs\Prune;

use App\Libs\Attributes\Cli\Prune;
use App\Libs\Attributes\DI\ForModel;
use App\Libs\Config;
use App\Models\MediaHealthReport;
use App\Models\MediaHealthReportItem;
use arabcoders\database\Orm\EntityRepository;
use arabcoders\database\Query\Condition;
use Psr\Log\LoggerInterface as iLogger;

#[Prune(name: 'Media Health Reports', cron: '20 5 * * *', desc: 'Remove old media health audit reports.')]
final class MediaHealthReportPruner
{
    /**
     * @var EntityRepository<MediaHealthReport> $reports
     * @var EntityRepository<MediaHealthReportItem> $items
     */
    public function __construct(
        private readonly iLogger $logger,
        #[ForModel(MediaHealthReport::class)]
        private readonly EntityRepository $reports,
        #[ForModel(MediaHealthReportItem::class)]
        private readonly EntityRepository $items,
    ) {}

    public function __invoke(bool $execute): void
    {
        $keep = max(1, (int) Config::get('media_health.keep', 3));

        $expired = $this->reports->findBy(
            limit: null,
            offset: $keep,
            orderBy: ['generated_at' => 'DESC', 'id' => 'DESC'],
            columns: ['id'],
        );

        $ids = array_values(array_filter(array_map(
            static fn(MediaHealthReport $report): int => (int) $report->id,
            $expired,
        )));

        if ([] === $ids) {
            $this->logger->debug('No old media health audit reports found.', [
                'operation' => 'prune.media_health',
                'error' => 'no_expired_reports',
                'keep' => $keep,
            ]);
            return;
        }

        $count = $this->countRows($ids);

        if (true === $execute) {
            $this->items->deleteWhere(Condition::in('report_id', $ids));
            $this->reports->deleteWhere(Condition::in('id', $ids));
            $this->deleteExports($ids);
        }

        $this->logger->info("{action} '{count}' old media health audit rows.", [
            'action' => true === $execute ? 'Pruned' : 'Found',
            'operation' => 'prune.media_health',
            'count' => $count,
            'reports' => count($ids),
            'keep' => $keep,
        ]);
    }

    /**
     * @param array<int,int> $ids
     */
    private function countRows(array $ids): int
    {
        return $this->reports->countWhere(Condition::in('id', $ids)) + $this->items->countWhere(Condition::in('report_id', $ids));
    }

    /**
     * @param array<int,int> $ids
     */
    private function deleteExports(array $ids): void
    {
        foreach ($ids as $id) {
            foreach (['json.json', 'markdown.md', 'csv.csv'] as $format) {
                $file = r((string) Config::get('media_health.path') . '/{file}', [
                    'file' => 'media-health-' . $id . '-' . $format . '.zip',
                ]);
                if (file_exists($file) && is_file($file)) {
                    @unlink($file);
                }
            }
        }
    }
}
