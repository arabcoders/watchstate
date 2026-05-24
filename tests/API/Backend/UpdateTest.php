<?php

declare(strict_types=1);

namespace Tests\API\Backend;

use App\API\Backend\Update;
use App\Libs\Config;
use App\Libs\Enums\Http\Method;
use App\Libs\Enums\Http\Status;
use App\Libs\Mappers\ImportInterface as iImport;
use App\Libs\TestCase;
use Monolog\Logger;
use Symfony\Component\Yaml\Yaml;
use Tests\Support\FakeBackendClient;
use Tests\Support\RequestResponseTrait;
use Tests\Support\TokenAwareBackendClient;

final class UpdateTest extends TestCase
{
    use RequestResponseTrait;

    protected function setUp(): void
    {
        parent::setUp();

        $this->initTempApp();
        Config::save('supported.fake', FakeBackendClient::class);

        file_put_contents((string) Config::get('backends_file'), Yaml::dump([
            'backend1' => [
                'type' => 'fake',
                'url' => 'http://backend1.example.invalid',
                'token' => 'token1',
                'user' => 'user-1',
                'uuid' => 'uuid-1',
                'webhook' => [],
                'options' => [
                    'IMPORT_METADATA_ONLY' => true,
                    'use_old_progress_endpoint' => true,
                    'keep' => 'value1',
                ],
                'import' => [
                    'enabled' => true,
                ],
                'export' => [
                    'enabled' => true,
                ],
            ],
            'backend2' => [
                'type' => 'fake',
                'url' => 'http://backend2.example.invalid',
                'token' => 'token2',
                'user' => 'user-2',
                'uuid' => 'uuid-2',
                'webhook' => [],
                'options' => [
                    'IMPORT_METADATA_ONLY' => true,
                    'use_old_progress_endpoint' => true,
                    'keep' => 'value2',
                ],
                'import' => [
                    'enabled' => true,
                ],
                'export' => [
                    'enabled' => true,
                ],
            ],
        ], 8, 2));
    }

    public function test_update_strips_deprecated_keys(): void
    {
        $handler = new Update($this->createStub(iImport::class), new Logger('test'));

        $response = $handler->update(
            $this->getRequest(
                method: Method::PUT,
                uri: '/v1/api/backend/backend1',
                post: [
                    'url' => 'http://backend1.example.invalid',
                    'token' => 'new_token',
                    'user' => 'user-1',
                    'uuid' => 'uuid-1',
                    'import' => [
                        'enabled' => true,
                    ],
                    'export' => [
                        'enabled' => true,
                    ],
                ],
            ),
            'backend1',
        );

        self::assertSame(Status::OK->value, $response->getStatusCode());

        $saved = Yaml::parseFile((string) Config::get('backends_file'));

        self::assertSame('new_token', ag($saved, 'backend1.token'));
        self::assertSame('token2', ag($saved, 'backend2.token'));
        self::assertSame('value1', ag($saved, 'backend1.options.keep'));
        self::assertSame('value2', ag($saved, 'backend2.options.keep'));
        self::assertFalse(ag_exists(ag($saved, 'backend1', []), 'webhook'));
        self::assertFalse(ag_exists(ag($saved, 'backend2', []), 'webhook'));
        self::assertFalse(ag_exists(ag($saved, 'backend1.options', []), 'use_old_progress_endpoint'));
        self::assertFalse(ag_exists(ag($saved, 'backend2.options', []), 'use_old_progress_endpoint'));
        self::assertFalse(ag_exists(ag($saved, 'backend1.options', []), 'IMPORT_METADATA_ONLY'));
        self::assertFalse(ag_exists(ag($saved, 'backend2.options', []), 'IMPORT_METADATA_ONLY'));
    }

    public function test_rejects_bad_token(): void
    {
        Config::save('supported.fake', TokenAwareBackendClient::class);

        $handler = new Update($this->createStub(iImport::class), new Logger('test'));

        $response = $handler->update(
            $this->getRequest(
                method: Method::PUT,
                uri: '/v1/api/backend/backend1',
                post: [
                    'url' => 'http://backend1.example.invalid',
                    'token' => 'bad-token',
                    'user' => 'user-1',
                    'uuid' => 'uuid-1',
                    'import' => [
                        'enabled' => true,
                    ],
                    'export' => [
                        'enabled' => true,
                    ],
                ],
            ),
            'backend1',
        );

        self::assertSame(Status::BAD_REQUEST->value, $response->getStatusCode());

        $payload = json_decode((string) $response->getBody(), true, flags: JSON_THROW_ON_ERROR);
        $saved = Yaml::parseFile((string) Config::get('backends_file'));

        self::assertSame('Backend responded with 401. Most likely means token is invalid.', ag($payload, 'error.message'));
        self::assertSame('token1', ag($saved, 'backend1.token'));
    }

    public function test_accepts_recovered_token(): void
    {
        Config::save('supported.fake', TokenAwareBackendClient::class);

        file_put_contents((string) Config::get('backends_file'), Yaml::dump([
            'backend1' => [
                'type' => 'fake',
                'url' => 'http://backend1.example.invalid',
                'token' => 'bad-token',
                'user' => 'user-1',
                'uuid' => 'uuid-1',
                'options' => [],
                'import' => [
                    'enabled' => true,
                ],
                'export' => [
                    'enabled' => true,
                ],
            ],
        ], 8, 2));

        $handler = new Update($this->createStub(iImport::class), new Logger('test'));

        $response = $handler->update(
            $this->getRequest(
                method: Method::PUT,
                uri: '/v1/api/backend/backend1',
                post: [
                    'url' => 'http://backend1.example.invalid',
                    'token' => 'good-token',
                    'user' => 'user-1',
                    'uuid' => 'uuid-1',
                    'import' => [
                        'enabled' => true,
                    ],
                    'export' => [
                        'enabled' => true,
                    ],
                ],
            ),
            'backend1',
        );

        self::assertSame(Status::OK->value, $response->getStatusCode());

        $saved = Yaml::parseFile((string) Config::get('backends_file'));

        self::assertSame('good-token', ag($saved, 'backend1.token'));
    }
}
