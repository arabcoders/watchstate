<?php

declare(strict_types=1);

namespace Tests\Backends\MediaBrowser;

use App\Backends\Emby\Action\Import as EmbyImport;
use App\Backends\Emby\EmbyGuid;
use App\Backends\Jellyfin\Action\Import as JellyfinImport;
use App\Backends\Jellyfin\JellyfinGuid;
use App\Libs\Config;
use App\Libs\Entity\StateInterface as iState;
use App\Libs\Options;
use PHPUnit\Framework\Attributes\DataProvider;
use ReflectionMethod;

class PlayedDateTest extends MediaBrowserTestCase
{
    #[DataProvider('playedCases')]
    public function test_stale_played(
        string $client,
        bool $enabled,
        ?int $playCount,
        ?int $position,
        bool $expected,
    ): void {
        $key = 'clients.' . strtolower($client) . '.fix_played';
        $otherKey = 'clients.' . ('Emby' === $client ? 'jellyfin' : 'emby') . '.fix_played';
        $previous = Config::get($key, false);
        $otherPrevious = Config::get($otherKey, false);
        Config::save($key, $enabled);
        Config::save($otherKey, true);

        try {
            $context = $this->makeContext($client);
            $action = 'Emby' === $client
                ? new EmbyImport($this->makeHttpClient(), $this->logger)
                : new JellyfinImport($this->makeHttpClient(), $this->logger);
            $guid = 'Emby' === $client ? new EmbyGuid($this->logger) : new JellyfinGuid($this->logger);
            $guid = $guid->withContext($context);
            $method = new ReflectionMethod($action, 'createEntity');
            $item = $this->fixture('metadata_episode');
            $item['UserData']['Played'] = false;
            $item['UserData']['PlayCount'] = 0;
            $item['UserData']['PlaybackPositionTicks'] = 900000000;
            $local = $method->invoke($action, $context, $guid, $item);
            self::assertInstanceOf(iState::class, $local);
            $context->userContext->db->commit([$local]);
            $context->userContext->mapper->loadData();

            $item['UserData']['Played'] = true;
            $item['UserData']['LastPlayedDate'] = '2024-01-01T00:00:00Z';
            if (null === $playCount) {
                unset($item['UserData']['PlayCount']);
            } else {
                $item['UserData']['PlayCount'] = $playCount;
            }
            if (null === $position) {
                unset($item['UserData']['PlaybackPositionTicks']);
            } else {
                $item['UserData']['PlaybackPositionTicks'] = $position;
            }

            $remote = $method->invoke($action, $context, $guid, $item);
            self::assertInstanceOf(iState::class, $remote);
            self::assertSame($expected, $remote->getContext('should_mark', false));
            $after = make_date('2024-01-01T01:00:00Z');
            $context->userContext->mapper->add($remote, [Options::AFTER => $after]);

            $stored = $context->userContext->db->get($local);
            self::assertNotNull($stored);
            self::assertSame($expected, $stored->isWatched());
            if (true === $expected) {
                self::assertSame($after->getTimestamp() + 1, $stored->updated);
                self::assertSame($stored->updated, $stored->getMetadata($client)[iState::COLUMN_META_DATA_PLAYED_AT]);
            }
        } finally {
            Config::save($key, $previous);
            Config::save($otherKey, $otherPrevious);
        }
    }

    /**
     * @return array<string,array{string,bool,?int,?int,bool}>
     */
    public static function playedCases(): array
    {
        return [
            'emby_enabled' => ['Emby', true, 1, 0, true],
            'emby_disabled' => ['Emby', false, 1, 0, false],
            'emby_progress' => ['Emby', true, 1, 900000000, false],
            'emby_zero_count' => ['Emby', true, 0, 0, true],
            'emby_no_count' => ['Emby', true, null, 0, true],
            'emby_disabled_zero_count' => ['Emby', false, 0, 0, false],
            'emby_no_position' => ['Emby', true, 1, null, false],
            'jellyfin_enabled' => ['Jellyfin', true, 1, 0, true],
            'jellyfin_disabled' => ['Jellyfin', false, 1, 0, false],
            'jellyfin_zero_count' => ['Jellyfin', true, 0, 0, false],
            'jellyfin_no_count' => ['Jellyfin', true, null, 0, false],
        ];
    }
}
