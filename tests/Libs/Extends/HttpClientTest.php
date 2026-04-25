<?php

declare(strict_types=1);

namespace Tests\Libs\Extends;

use App\Libs\Enums\Http\Method;
use App\Libs\Extends\HttpClient;
use App\Libs\Extends\MockHttpClient;
use Monolog\Handler\TestHandler;
use Monolog\Logger;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\Response\MockResponse;

final class HttpClientTest extends TestCase
{
    public function test_logger_preserves_query_parameter_names(): void
    {
        $handler = new TestHandler();
        $logger = new Logger('test', [$handler]);

        $client = new HttpClient(new MockHttpClient(new MockResponse('ok', ['http_code' => 200])));
        $client->setLogger($logger);

        $client->request(
            Method::GET,
            'https://example.test/items?userId=user-1&enableUserData=true&enableImages=false',
            ['headers' => ['Authorization' => 'secret']],
        );

        $records = $handler->getRecords();

        self::assertCount(1, $records);
        self::assertSame(
            'https://example.test/items?userId=user-1&enableUserData=true&enableImages=false',
            $records[0]['context']['url'],
        );
    }
}
