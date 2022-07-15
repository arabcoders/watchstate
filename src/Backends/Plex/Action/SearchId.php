<?php

declare(strict_types=1);

namespace App\Backends\Plex\Action;

use App\Backends\Common\CommonTrait;
use App\Backends\Common\Context;
use App\Backends\Common\Response;
use App\Backends\Plex\PlexActionTrait;
use App\Libs\Options;
use Psr\Log\LoggerInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

final class SearchId
{
    use CommonTrait;
    use PlexActionTrait;

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

        $metadata = ag($item, 'MediaContainer.Metadata.0', []);

        $type = ag($metadata, 'type');
        $watchedAt = ag($metadata, 'lastViewedAt');

        $year = (int)ag($metadata, ['grandParentYear', 'parentYear', 'year'], 0);
        if (0 === $year && null !== ($airDate = ag($metadata, 'originallyAvailableAt'))) {
            $year = (int)makeDate($airDate)->format('Y');
        }

        $episodeNumber = ('episode' === $type) ? sprintf(
            '%sx%s - ',
            str_pad((string)(ag($metadata, 'parentIndex', 0)), 2, '0', STR_PAD_LEFT),
            str_pad((string)(ag($metadata, 'index', 0)), 3, '0', STR_PAD_LEFT),
        ) : null;

        $builder = [
            'id' => (int)ag($metadata, 'ratingKey'),
            'type' => ucfirst(ag($metadata, 'type', '??')),
            'library' => ag($metadata, 'librarySectionTitle', '??'),
            'title' => $episodeNumber . mb_substr(ag($metadata, ['title', 'originalTitle'], '??'), 0, 50),
            'year' => $year,
            'addedAt' => makeDate(ag($metadata, 'addedAt'))->format('Y-m-d H:i:s T'),
            'watchedAt' => null !== $watchedAt ? makeDate($watchedAt)->format('Y-m-d H:i:s T') : 'Never',
            'duration' => ag($metadata, 'duration') ? formatDuration(ag($metadata, 'duration')) : 'None',
        ];

        if (true === (bool)ag($opts, Options::RAW_RESPONSE)) {
            $builder['raw'] = $item;
        }

        return new Response(status: true, response: $builder);
    }
}
