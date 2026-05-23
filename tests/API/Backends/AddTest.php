<?php

declare(strict_types=1);

namespace Tests\API\Backends;

use App\API\Backends\Add;
use App\Libs\Config;
use App\Libs\Enums\Http\Method;
use App\Libs\Enums\Http\Status;
use App\Libs\Mappers\ImportInterface as iImport;
use App\Libs\TestCase;
use Monolog\Handler\TestHandler;
use Monolog\Logger;
use Tests\Support\FailingValidateBackendClient;
use Tests\Support\RequestResponseTrait;

final class AddTest extends TestCase
{
    use RequestResponseTrait;

    public function test_add_logs_validate_html(): void
    {
        $this->initTempApp();
        Config::save('supported.fake', FailingValidateBackendClient::class);

        $handler = new TestHandler();
        $logger = new Logger('test', [$handler]);

        $response = (new Add())->BackendAdd(
            $this->getRequest(
                method: Method::POST,
                uri: '/v1/api/backends',
                post: [
                    'type' => 'fake',
                    'name' => 'backend1',
                    'url' => 'http://backend1.example.invalid',
                    'token' => 'token1',
                    'user' => 'user-1',
                ],
            ),
            $this->createStub(iImport::class),
            $logger,
        );

        self::assertSame(Status::BAD_REQUEST->value, $response->getStatusCode());

        $payload = json_decode((string) $response->getBody(), true, flags: JSON_THROW_ON_ERROR);

        self::assertSame(
            'Backend returned a non-JSON response from /system/Info. Response content-type was text/html.',
            ag($payload, 'error.message'),
        );

        $records = $handler->getRecords();
        $record = end($records);

        self::assertSame('backend.context.validation_failed', $record->context['event_name'] ?? null);
        self::assertSame('backend1', $record->context['backend'] ?? null);
        self::assertSame('fake', $record->context['backend_type'] ?? null);
        self::assertSame('http://backend1.example.invalid/system/Info', $record->context['http']['url'] ?? null);
        self::assertSame(200, $record->context['http']['status_code'] ?? null);
        self::assertSame('text/html', $record->context['response']['content_type'] ?? null);
    }
}
