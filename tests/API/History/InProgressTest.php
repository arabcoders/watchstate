<?php

declare(strict_types=1);

namespace Tests\API\History;

use App\API\History\Index;
use App\Libs\Config;
use App\Libs\Container;
use App\Libs\Entity\StateEntity;
use App\Libs\Entity\StateInterface as iState;
use App\Libs\TestCase;
use App\Libs\UserContext;
use Monolog\Handler\NullHandler;
use Monolog\Logger;
use Tests\Support\RequestResponseTrait;
use Tests\Support\StateCommandTestSupport;

final class InProgressTest extends TestCase
{
    use RequestResponseTrait;
    use StateCommandTestSupport;

    private UserContext $context;
    private Index $api;

    protected function setUp(): void
    {
        parent::setUp();

        $this->initTempApp();
        $this->seedTestServersConfig();
        $this->context = $this->makeUserContext('main', new Logger('test', [new NullHandler()]));
        $this->api = Container::get(Index::class);
        Config::save('progress.minimum', 60_000);
        Config::save('progress.threshold', 0);
    }

    public function test_scope_paging(): void
    {
        $metadata = ['alpha' => ['progress' => 60_001], 'beta' => ['progress' => 70_000]];
        $this->record('First', iState::TYPE_MOVIE, $metadata);
        $this->record('Second', iState::TYPE_MOVIE, $metadata);
        $this->record('Episode', iState::TYPE_EPISODE, $metadata);
        $this->record('Watched', iState::TYPE_MOVIE, $metadata, 1);
        $this->record('Unstarted', iState::TYPE_MOVIE, []);

        $query = ['in_progress' => '1', 'type' => 'movie', 'perpage' => 1, 'sort' => ['id:asc']];
        $response = $this->api->list($this->getRequest(query: $query));
        $payload = json_decode((string) $response->getBody(), true, flags: JSON_THROW_ON_ERROR);

        self::assertSame(200, $response->getStatusCode());
        self::assertSame(2, $payload['paging']['total']);
        self::assertSame(2, $payload['paging']['next_page']);
        self::assertSame(['First'], array_column($payload['history'], 'title'));
        self::assertSame('1', $payload['filters']['in_progress']);
        self::assertSame(['1'], array_column($payload['searchable'], null, 'key')['in_progress']['type']);
        self::assertStringContainsString('in_progress=1', $payload['links']['next_url']);

        $response = $this->api->list($this->getRequest(query: [...$query, 'page' => 2]));
        $payload = json_decode((string) $response->getBody(), true, flags: JSON_THROW_ON_ERROR);

        self::assertSame(2, $payload['paging']['total']);
        self::assertNull($payload['paging']['next_page']);
        self::assertSame(['Second'], array_column($payload['history'], 'title'));

        $response = $this->api->list($this->getRequest(query: ['in_progress' => '1', 'type' => 'episode']));
        $payload = json_decode((string) $response->getBody(), true, flags: JSON_THROW_ON_ERROR);

        self::assertSame(1, $payload['paging']['total']);
        self::assertSame(['Episode'], array_column($payload['history'], 'title'));
    }

    public function test_minimum(): void
    {
        foreach ([59_999, 60_000, 60_001] as $progress) {
            $this->record((string) $progress, iState::TYPE_MOVIE, ['alpha' => ['progress' => $progress]]);
        }

        $response = $this->api->list($this->getRequest(query: ['in_progress' => '1']));
        $payload = json_decode((string) $response->getBody(), true, flags: JSON_THROW_ON_ERROR);

        self::assertSame(1, $payload['paging']['total']);
        self::assertSame(['60001'], array_column($payload['history'], 'title'));

        Config::save('progress.minimum', 59_999);
        $response = $this->api->list($this->getRequest(query: ['in_progress' => '1', 'sort' => ['id:asc']]));
        $payload = json_decode((string) $response->getBody(), true, flags: JSON_THROW_ON_ERROR);

        self::assertSame(2, $payload['paging']['total']);
        self::assertSame(['60000', '60001'], array_column($payload['history'], 'title'));
    }

    public function test_metadata_watched(): void
    {
        $this->record('Backend watched', iState::TYPE_MOVIE, ['alpha' => ['progress' => 70_000, 'watched' => '1']]);
        $this->record('Backend unwatched', iState::TYPE_MOVIE, ['alpha' => ['progress' => '70000', 'watched' => '0']]);
        $this->record('Missing watched', iState::TYPE_MOVIE, ['alpha' => ['progress' => 70_000]]);
        $this->record('Other backend', iState::TYPE_MOVIE, [
            'alpha' => ['progress' => 70_000, 'watched' => 1],
            'beta' => ['progress' => 70_000, 'watched' => 0],
        ]);
        $this->record('Globally watched', iState::TYPE_MOVIE, ['alpha' => ['progress' => 70_000]], 1);

        foreach ([0 => 3, 1 => 4] as $threshold => $total) {
            Config::save('progress.threshold', $threshold);
            $response = $this->api->list($this->getRequest(query: ['in_progress' => '1', 'sort' => ['id:asc']]));
            $payload = json_decode((string) $response->getBody(), true, flags: JSON_THROW_ON_ERROR);

            self::assertSame(200, $response->getStatusCode());
            self::assertSame($total, $payload['paging']['total']);
            self::assertNotContains('Globally watched', array_column($payload['history'], 'title'));
            self::assertSame(1 === $threshold, in_array('Backend watched', array_column($payload['history'], 'title'), true));
        }
    }

    public function test_metadata_shapes(): void
    {
        foreach ([null, 'not JSON', 70_000, true, [], ['progress' => null], ['watched' => 0]] as $entry) {
            $this->record('No progress', iState::TYPE_MOVIE, ['alpha' => $entry]);
        }

        $this->record('Missing metadata', iState::TYPE_MOVIE, []);
        $this->record('Progress', iState::TYPE_MOVIE, ['alpha' => ['progress' => 70_000]]);
        $response = $this->api->list($this->getRequest(query: ['in_progress' => '1']));
        $payload = json_decode((string) $response->getBody(), true, flags: JSON_THROW_ON_ERROR);

        self::assertSame(200, $response->getStatusCode());
        self::assertSame(1, $payload['paging']['total']);
        self::assertSame(['Progress'], array_column($payload['history'], 'title'));
    }

    /**
     * @param array<string,mixed> $metadata
     */
    private function record(string $title, string $type, array $metadata, int $watched = 0): void
    {
        $entity = $this->context->db->insert(new StateEntity([
            iState::COLUMN_TYPE => $type,
            iState::COLUMN_TITLE => $title,
            iState::COLUMN_WATCHED => $watched,
            iState::COLUMN_VIA => 'alpha',
            iState::COLUMN_SEASON => iState::TYPE_EPISODE === $type ? 1 : null,
            iState::COLUMN_EPISODE => iState::TYPE_EPISODE === $type ? 1 : null,
        ]));

        $this->context
            ->db
            ->getDBLayer()
            ->query('UPDATE state SET metadata = :metadata WHERE id = :id', [
                'metadata' => json_encode($metadata, flags: JSON_THROW_ON_ERROR),
                'id' => $entity->id,
            ]);
    }
}
