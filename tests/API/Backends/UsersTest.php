<?php

declare(strict_types=1);

namespace Tests\API\Backends;

use App\API\Backends\Users;
use App\Libs\Config;
use App\Libs\Enums\Http\Method;
use App\Libs\Enums\Http\Status;
use App\Libs\TestCase;
use Monolog\Handler\TestHandler;
use Monolog\Logger;
use Tests\Support\FailingValidateBackendClient;
use Tests\Support\RequestResponseTrait;

final class UsersTest extends TestCase
{
    use RequestResponseTrait;

    public function test_users_returns_details(): void
    {
        $this->initTempApp();
        Config::save('supported.fake', FailingValidateBackendClient::class);

        $handler = new TestHandler();
        $logger = new Logger('test', [$handler]);

        $response = (new Users())(
            $this->getRequest(
                method: Method::POST,
                uri: '/v1/api/backends/users/fake',
                post: [
                    'name' => 'backend1',
                    'type' => 'fake',
                    'url' => 'http://backend1.example.invalid',
                    'token' => 'token1',
                    'uuid' => 'uuid-1',
                    'user' => 'user-1',
                ],
            ),
            'fake',
            $logger,
        );

        self::assertSame(Status::INTERNAL_SERVER_ERROR->value, $response->getStatusCode());

        $payload = json_decode((string) $response->getBody(), true, flags: JSON_THROW_ON_ERROR);

        self::assertSame('Backend returned a non-JSON response from /system/Info. Response content-type was text/html.', ag($payload, 'error.message'));

        $records = $handler->getRecords();
        $record = end($records);

        self::assertSame('backend.context.users_failed', $record->context['event_name'] ?? null);
        self::assertSame('fake', $record->context['backend_type'] ?? null);
        self::assertSame('http://backend1.example.invalid/system/Info', $record->context['http']['url'] ?? null);
        self::assertSame(200, $record->context['http']['status_code'] ?? null);
        self::assertSame('text/html', $record->context['response']['content_type'] ?? null);
    }
}
