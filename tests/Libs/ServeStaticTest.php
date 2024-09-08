<?php
/** @noinspection PhpUnhandledExceptionInspection */

declare(strict_types=1);

namespace Tests\Libs;

use App\Libs\Config;
use App\Libs\Enums\Http\Status;
use App\Libs\ServeStatic;
use App\Libs\TestCase;
use Nyholm\Psr7\ServerRequest;

class ServeStaticTest extends TestCase
{
    private ServeStatic|null $server = null;
    private string $dataPath = __DIR__ . '/../Fixtures/static_data';

    protected function setUp(): void
    {
        parent::setUp();
        $this->server = new ServeStatic(realpath($this->dataPath));
    }

    private function createRequest(string $method, string $uri, array $headers = []): ServerRequest
    {
        return new ServerRequest($method, $uri, $headers);
    }

    public function test_responses()
    {
        $this->checkException(
            closure: fn() => $this->server->serve($this->createRequest('GET', '/nonexistent')),
            reason: 'If file does not exist, A NotFoundException should be thrown.',
            exception: \League\Route\Http\Exception\NotFoundException::class,
            exceptionMessage: 'not found',
            exceptionCode: Status::NOT_FOUND->value,
        );

        $this->checkException(
            closure: function () {
                Config::save('webui.path', '/nonexistent');
                return (new ServeStatic())->serve($this->createRequest('GET', '/nonexistent'));
            },
            reason: 'If file does not exist, A NotFoundException should be thrown.',
            exception: \League\Route\Http\Exception\BadRequestException::class,
            exceptionMessage: 'The static path',
            exceptionCode: Status::SERVICE_UNAVAILABLE->value,
        );

        $response = $this->server->serve($this->createRequest('GET', '/test.html'));
        $this->assertEquals(Status::OK->value, $response->getStatusCode());
        $this->assertEquals('text/html; charset=utf-8', $response->getHeaderLine('Content-Type'));
        $this->assertEquals(file_get_contents($this->dataPath . '/test.html'), (string)$response->getBody());
        $this->assertSame(filesize($this->dataPath . '/test.html'), $response->getBody()->getSize());

        // -- test screenshots serving, as screenshots path is not in public directory and not subject
        // -- to same path restrictions as other files.
        $response = $this->server->serve($this->createRequest('GET', '/screenshots/add_backend.png'));
        $this->assertEquals(Status::OK->value, $response->getStatusCode());
        $this->assertEquals('image/png', $response->getHeaderLine('Content-Type'));
        $this->assertEquals(
            file_get_contents(__DIR__ . '/../../screenshots/add_backend.png'),
            (string)$response->getBody()
        );

        // -- There are similar rules for .md files test them.
        $response = $this->server->serve($this->createRequest('GET', '/README.md'));
        $this->assertEquals(Status::OK->value, $response->getStatusCode());
        $this->assertEquals('text/markdown; charset=utf-8', $response->getHeaderLine('Content-Type'));
        $this->assertEquals(file_get_contents(__DIR__ . '/../../README.md'), (string)$response->getBody());

        $this->checkException(
            closure: fn() => $this->server->serve($this->createRequest('PUT', '/nonexistent.md')),
            reason: 'Non-idempotent methods should not be allowed on static files.',
            exception: \League\Route\Http\Exception\BadRequestException::class,
            exceptionMessage: 'is not allowed',
        );

        // -- Check directory serving.
        $response = $this->server->serve($this->createRequest('GET', '/test'));
        $this->assertEquals(Status::OK->value, $response->getStatusCode());
        $this->assertEquals('text/html; charset=utf-8', $response->getHeaderLine('Content-Type'));
        $this->assertEquals(
            file_get_contents(__DIR__ . '/../Fixtures/static_data/test/index.html'),
            (string)$response->getBody()
        );

        $response = $this->server->serve($this->createRequest('GET', '/test.html', [
            'if-modified-since' => gmdate('D, d M Y H:i:s T', filemtime($this->dataPath . '/test.html')),
        ]));

        $this->assertEquals(Status::NOT_MODIFIED->value, $response->getStatusCode());

        // -- Check for LFI vulnerability.
        $this->checkException(
            closure: fn() => $this->server->serve($this->createRequest('GET', '/../../../composer.json')),
            reason: 'Should not allow serving files outside the static directory.',
            exception: \League\Route\Http\Exception\BadRequestException::class,
            exceptionMessage: 'is invalid.',
            exceptionCode: Status::BAD_REQUEST->value,
        );

        // -- Check for invalid root static path.
        $this->checkException(
            closure: fn() => (new ServeStatic('/nonexistent'))->serve($this->createRequest('GET', '/test.html')),
            reason: 'Should throw exception if the static path does not exist.',
            exception: \League\Route\Http\Exception\BadRequestException::class,
            exceptionMessage: 'The static path',
            exceptionCode: Status::SERVICE_UNAVAILABLE->value,
        );

        // -- check for deep index lookup.
        $response = $this->server->serve($this->createRequest('GET', '/test/view/action/1'));
        $this->assertEquals(Status::OK->value, $response->getStatusCode());
        $this->assertEquals('text/html; charset=utf-8', $response->getHeaderLine('Content-Type'));
        $this->assertEquals(
            file_get_contents(__DIR__ . '/../Fixtures/static_data/test/index.html'),
            (string)$response->getBody()
        );

        $response = $this->server->serve($this->createRequest('GET', '/test/view/1'));
        $this->assertEquals(Status::OK->value, $response->getStatusCode());

        $this->checkException(
            closure: fn() => $this->server->serve($this->createRequest('GET', '/test2/foo/bar')),
            reason: 'If file does not exist, A NotFoundException should be thrown.',
            exception: \League\Route\Http\Exception\NotFoundException::class,
            exceptionMessage: 'not found',
            exceptionCode: Status::NOT_FOUND->value,
        );

        $this->checkException(
            closure: fn() => $this->server->serve($this->createRequest('GET', '/')),
            reason: 'If file does not exist, A NotFoundException should be thrown.',
            exception: \League\Route\Http\Exception\NotFoundException::class,
            exceptionMessage: 'not found',
            exceptionCode: Status::NOT_FOUND->value,
        );

        $response = $this->server->serve($this->createRequest('GET', '/test.html', [
            'if-modified-since' => '$$ INVALID DATA',
        ]));

        $this->assertEquals(
            Status::OK->value,
            $response->getStatusCode(),
            'If the date is invalid, the file should be served as normal.'
        );
    }
}
