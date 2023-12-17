<?php

declare(strict_types=1);

namespace App\Backends\Jellyfin\Action;

use App\Backends\Common\CommonTrait;
use App\Backends\Common\Context;
use App\Backends\Common\Response;
use App\Backends\Jellyfin\JellyfinActionTrait;
use App\Libs\Exceptions\Backends\RuntimeException;
use App\Libs\Options;
use Psr\Log\LoggerInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Class SearchId
 *
 * SearchId class is responsible for searching and retrieving details about an item with a given ID from the Jellyfin API.
 */
class SearchId
{
    use CommonTrait;
    use JellyfinActionTrait;

    /**
     * @var string Action name.
     */
    protected string $action = 'jellyfin.searchId';

    /**
     * Class Constructor.
     *
     * @param HttpClientInterface $http The HTTP client.
     * @param LoggerInterface $logger The logger.
     */
    public function __construct(protected HttpClientInterface $http, protected LoggerInterface $logger)
    {
    }

    /**
     * Wrap the operation in a try response block.
     *
     * @param Context $context Backend context.
     * @param string|int $id The ID of the item to search for.
     * @param array $opts (optional) options.
     *
     * @return Response The response.
     */
    public function __invoke(Context $context, string|int $id, array $opts = []): Response
    {
        return $this->tryResponse(
            context: $context,
            fn: fn() => $this->search($context, $id, $opts),
            action: $this->action
        );
    }

    /**
     * Fetch details about ID from jellyfin API.
     *
     * @param Context $context Backend context.
     * @param string|int $id The ID of the item to search for.
     * @param array $opts (Optional) options.
     *
     * @return Response The response.
     * @throws RuntimeException When API call was not successful.
     */
    private function search(Context $context, string|int $id, array $opts = []): Response
    {
        $item = $this->getItemDetails($context, $id, $opts);

        $year = (int)ag($item, 'Year', 0);

        if (0 === $year && null !== ($airDate = ag($item, 'PremiereDate'))) {
            $year = (int)makeDate($airDate)->format('Y');
        }

        $type = strtolower(ag($item, 'Type'));

        $episodeNumber = ('episode' === $type) ? r('{season}x{episode} - ', [
            'season' => str_pad((string)(ag($item, 'ParentIndexNumber', 0)), 2, '0', STR_PAD_LEFT),
            'episode' => str_pad((string)(ag($item, 'IndexNumber', 0)), 3, '0', STR_PAD_LEFT),
        ]) : null;

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
