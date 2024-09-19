<?php

declare(strict_types=1);

namespace Tests\Libs\Traits;

use App\Backends\Common\ClientInterface as iClient;
use App\Backends\Plex\PlexClient;
use App\Libs\Config;
use App\Libs\Container;
use App\Libs\DataUtil;
use App\Libs\Entity\StateInterface as iState;
use App\Libs\Exceptions\InvalidArgumentException;
use App\Libs\Exceptions\RuntimeException;
use App\Libs\Options;
use App\Libs\TestCase;
use App\Libs\Traits\APITraits;

class APITraitsTest extends TestCase
{
    protected function setUp(): void
    {
        Container::init();
        Config::init(require __DIR__ . '/../../../config/config.php');
        foreach ((array)require __DIR__ . '/../../../config/services.php' as $name => $definition) {
            Container::add($name, $definition);
        }
        Config::save('backends_file', __DIR__ . '/../../Fixtures/test_servers.yaml');
        Config::save('api.secure', true);

        parent::setUp();
    }

    public function __destruct()
    {
        Config::reset();
        Container::reset();
    }

    public function test_getClient()
    {
        $trait = new class() {
            use APITraits {
                getClient as public;
            }
        };

        $client = $trait->getClient('test_plex');
        $this->assertInstanceOf(PlexClient::class, $client, 'getClient() should return a PlexClient instance');
        $this->checkException(
            closure: fn() => $trait->getClient('test_unknown'),
            reason: 'getClient() should throw a RuntimeException when the backend is not found',
            exception: RuntimeException::class,
            exceptionCode: 1000,
        );
    }

    public function test_getBackends()
    {
        $trait = new class() {
            use APITraits {
                getBackends as public;
            }
        };

        $data = $trait->getBackends();
        $this->assertCount(3, $data, 'getBackends() should return an array with 2 elements');

        $data = $trait->getBackends('test_plex');
        $this->assertCount(1, $data, 'getBackends() When filtering by name, should return an array with 1 element');
    }

    public function test_getBackend()
    {
        $trait = new class() {
            use APITraits {
                getBackend as public;
            }
        };

        $data = $trait->getBackend('test_plex');
        $this->assertSame('test_plex', ag($data, 'name'), 'getBackend() should return the backend data.');

        $this->assertNull(
            $trait->getBackend('not_set'),
            'getBackend() should return an empty array when the backend is not found.'
        );
    }

    public function test_formatEntity()
    {
        $trait = new class() {
            use APITraits {
                formatEntity as public;
            }
        };

        $entity = $trait->formatEntity(require __DIR__ . '/../../Fixtures/EpisodeEntity.php');
        $data = $trait->formatEntity($entity, includeContext: true);

        $keys = iState::ENTITY_KEYS;
        $keys[] = iState::COLUMN_META_DATA_PROGRESS;
        $keys[] = iState::COLUMN_EXTRA_EVENT;
        $keys[] = 'content_title';
        $keys[] = 'content_path';
        $keys[] = 'rguids';
        $keys[] = 'reported_by';
        $keys[] = 'webUrl';
        $keys[] = 'not_reported_by';
        $keys[] = 'isTainted';

        foreach ($keys as $key) {
            $this->assertArrayHasKey($key, $data, 'formatEntity() should return an array with the ' . $key . ' key.');
        }
    }

    public function test_getBasicClient()
    {
        $trait = new class() {
            use APITraits {
                getBasicClient as public;
            }
        };

        $data = DataUtil::fromArray([
            'type' => 'plex',
            'url' => 'http://localhost:32400',
            'token' => 'test_token',
            'options' => [
                Options::ADMIN_TOKEN => 'test_admin_token',
                Options::IS_LIMITED_TOKEN => true,
            ],
        ]);

        $this->checkException(
            closure: fn() => $trait->getBasicClient('not_set', $data),
            reason: 'getBasicClient() should throw an InvalidArgumentException when the type is not supported.',
            exception: InvalidArgumentException::class,
            exceptionCode: 2000,
        );

        $this->checkException(
            closure: fn() => $trait->getBasicClient('plex', $data->without('url')),
            reason: 'getBasicClient() should throw an InvalidArgumentException when the url is missing.',
            exception: InvalidArgumentException::class,
            exceptionCode: 2001,
        );

        $this->checkException(
            closure: fn() => $trait->getBasicClient('plex', $data->without('token')),
            reason: 'getBasicClient() should throw an InvalidArgumentException when the token is missing.',
            exception: InvalidArgumentException::class,
            exceptionCode: 2002,
        );

        $client = $trait->getBasicClient('plex', $data);
        $this->assertInstanceOf(iClient::class, $client, 'getBasicClient() should return a client instance.');

        $this->assertSame(
            $data->get('options.' . Options::ADMIN_TOKEN),
            ag($client->getContext()->options, Options::ADMIN_TOKEN),
            'Client getContext() should have the admin token registered.',
        );

        $this->assertSame(
            $data->get('options.' . Options::IS_LIMITED_TOKEN),
            ag($client->getContext()->options, Options::IS_LIMITED_TOKEN),
            'Client getContext() should have the is limited token registered.',
        );

        $this->assertTrue($client->getContext()->isLimitedToken(), 'Client isLimitedToken() should return true.');
    }
}
