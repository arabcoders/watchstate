<?php

declare(strict_types=1);

namespace App\Backends\Jellyfin\Action;

use App\Backends\Common\CommonTrait;
use App\Backends\Common\Context;
use App\Backends\Common\Response;
use App\Backends\Jellyfin\JellyfinActionTrait;
use App\Libs\Options;
use Psr\Log\LoggerInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

class SearchId
{
    use CommonTrait;
    use JellyfinActionTrait;

    public function __construct(protected HttpClientInterface $http, protected LoggerInterface $logger)
    {
    }

    /**
     * Search Backend for ID.
     *
     * @param Context $context
     * @param string|int $id
     * @param array $opts optional options.
     *
     * @return Response
     */
    public function __invoke(Context $context, string|int $id, array $opts = []): Response
    {
        return $this->tryResponse(context: $context, fn: fn() => $this->search($context, $id, $opts));
    }

    private function search(Context $context, string|int $id, array $opts = []): Response
    {
        $item = $this->getItemDetails($context, $id, $opts);

        $year = (int)ag($item, 'Year', 0);

        if (0 === $year && null !== ($airDate = ag($item, 'PremiereDate'))) {
            $year = (int)makeDate($airDate)->format('Y');
        }

        $type = strtolower(ag($item, 'Type'));

        $episodeNumber = ('episode' === $type) ? sprintf(
            '%sx%s - ',
            str_pad((string)(ag($item, 'ParentIndexNumber', 0)), 2, '0', STR_PAD_LEFT),
            str_pad((string)(ag($item, 'IndexNumber', 0)), 3, '0', STR_PAD_LEFT),
        ) : null;

        $builder = [
            'id' => ag($item, 'Id'),
            'type' => ucfirst($type),
            'title' => $episodeNumber . mb_substr(ag($item, ['Name', 'OriginalTitle'], '??'), 0, 50),
            'year' => $year,
            'addedAt' => makeDate(ag($item, 'DateCreated', 'now'))->format('Y-m-d H:i:s T'),
        ];

        if (null !== ($watchedAt = ag($item, 'UserData.LastPlayedDate'))) {
            $builder['watchedAt'] = makeDate($watchedAt)->format('Y-m-d H:i:s T');
        }

        if (null !== ($endDate = ag($item, 'EndDate'))) {
            $builder['EndedAt'] = makeDate($endDate)->format('Y-m-d H:i:s T');
        }

        if (('movie' === $type || 'series' === $type) && null !== ($premiereDate = ag($item, 'PremiereDate'))) {
            $builder['premieredAt'] = makeDate($premiereDate)->format('Y-m-d H:i:s T');
        }

        if (null !== $watchedAt) {
            $builder['watchedAt'] = makeDate($watchedAt)->format('Y-m-d H:i:s T');
        }

        if (('episode' === $type || 'movie' === $type) && null !== ($duration = ag($item, 'RunTimeTicks'))) {
            $builder['duration'] = formatDuration($duration / 10000);
        }

        if (null !== ($status = ag($item, 'Status'))) {
            $builder['status'] = $status;
        }

        if (true === (bool)ag($opts, Options::RAW_RESPONSE)) {
            $builder['raw'] = $item;
        }

        return new Response(status: true, response: $builder);
    }
}
