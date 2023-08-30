<?php

declare(strict_types=1);

namespace Tests\Server;

use App\Libs\Server;
use App\Libs\TestCase;
use Exception;
use RuntimeException;
use Symfony\Component\Process\Process;

class ServerTest extends TestCase
{
    private array $config = [];
    private Server|null $server = null;

    public function setUp(): void
    {
        try {
            $randomPort = random_int(50000, 65535);
        } catch (Exception) {
            $randomPort = 24587;
        }

        $this->config = [
            Server::CONFIG_HOST => '0.0.0.0',
            Server::CONFIG_PORT => $randomPort,
            Server::CONFIG_PHP => PHP_BINARY,
            Server::CONFIG_THREADS => 1,
            Server::CONFIG_ROOT => __DIR__,
            Server::CONFIG_ROUTER => __FILE__,
            Server::CONFIG_ENV => [
                'test_a' => 1,
                'test_b' => 2,
            ],
        ];

        $this->server = new Server($this->config);
        $this->server = $this->server->withENV($this->config[Server::CONFIG_ENV], true);
    }

    public function test_constructor_conditions(): void
    {
        $config = [
            Server::CONFIG_HOST => '0.0.0.1',
            Server::CONFIG_PORT => 8081,
            Server::CONFIG_ROUTER => __FILE__,
            Server::CONFIG_ROOT => __DIR__,
            Server::CONFIG_PHP => PHP_BINARY,
        ];

        $server = new Server($config);

        $this->assertEquals(
            array_intersect_key($server->getConfig(), $config),
            $config,
            'Should be equal config.'
        );

        $c = (new Server())->getConfig();

        $this->assertSame($c[Server::CONFIG_HOST], '0.0.0.0', 'Default host has changed.');
        $this->assertSame($c[Server::CONFIG_PORT], 8080, 'Default port has changed.');
        $this->assertSame($c[Server::CONFIG_THREADS], 1, 'Default threads changed.');
        $this->assertSame($c[Server::CONFIG_ROOT], realpath(__DIR__ . '/../../public'), 'Default root has changed.');
    }

    public function test_withPHP_conditions(): void
    {
        $this->assertSame(
            $this->server,
            $this->server->withPHP($this->config[Server::CONFIG_PHP]),
            'Should be same object.'
        );

        $this->expectException(RuntimeException::class);
        $this->server->withPHP($this->config[Server::CONFIG_PHP] . 'a');
    }

    public function test_withInterface_conditions(): void
    {
        $this->assertSame(
            $this->server,
            $this->server->withInterface($this->config[Server::CONFIG_HOST]),
            'Should return same object.'
        );

        $this->assertNotSame(
            $this->server,
            $this->server->withInterface('0.0.0.1'),
            'Should be different object.'
        );
    }

    public function test_getInterface_conditions(): void
    {
        $this->assertSame(
            $this->config[Server::CONFIG_HOST],
            $this->server->getInterface(),
            'Should be the same.'
        );
    }

    public function test_withPort_conditions(): void
    {
        $this->assertSame(
            $this->server,
            $this->server->withPort($this->config[Server::CONFIG_PORT]),
            'should return same object.'
        );

        $this->assertNotSame(
            $this->server,
            $this->server->withPort($this->config[Server::CONFIG_PORT] + 1),
            'Should not be same object.'
        );
    }

    public function test_getPort_conditions(): void
    {
        $this->assertSame(
            $this->config[Server::CONFIG_PORT],
            $this->server->getPort(),
            'Should be the same.'
        );
    }

    public function test_withThreads_conditions(): void
    {
        $this->assertSame(
            $this->server,
            $this->server->withThreads($this->config[Server::CONFIG_THREADS]),
            'Should return same object. As threads has not changed.'
        );

        $this->assertNotSame(
            $this->server,
            $this->server->withThreads($this->config[Server::CONFIG_THREADS] + 1),
            'Should return new object. As we have changed threads number.'
        );
    }

    public function test_withRoot_conditions(): void
    {
        $this->assertSame(
            $this->server,
            $this->server->withRoot($this->config[Server::CONFIG_ROOT]),
            'Should return same object.'
        );

        $this->expectException(RuntimeException::class);
        $this->server->withRoot($this->config[Server::CONFIG_ROOT] . $this->config[Server::CONFIG_ROOT]);
    }

    public function test_withRouter_conditions(): void
    {
        $this->assertSame(
            $this->server,
            $this->server->withRouter($this->config[Server::CONFIG_ROUTER])
        );

        $this->expectException(RuntimeException::class);
        $this->server->withRouter($this->config[Server::CONFIG_ROUTER] . 'zzz');
    }

    public function test_withENV_conditions(): void
    {
        $this->assertSame(
            $this->server,
            $this->server->withENV(['test_b' => 2]),
            'Supposed to return same object. As value did not change.'
        );

        $this->assertNotSame(
            $this->server,
            $this->server->withENV(['foo' => 'bar']),
            'Not supposed to return same object with changed value.'
        );
    }

    public function test_withoutENV_conditions(): void
    {
        $this->assertSame(
            $this->server,
            $this->server->withoutENV(['test_non_existent_env']),
            'Supposed to return same object. as key does not exists'
        );

        $this->assertNotSame(
            $this->server,
            $this->server->withoutENV(['test_a']),
            'Not supposed to return same object with changed value.'
        );

        $this->assertEquals(
            ['test_a' => 1],
            $this->server->withoutENV(['test_b'])->getConfig()[Server::CONFIG_ENV],
            'Should be identical array.'
        );
    }

    public function test_getProcess_conditions(): void
    {
        $this->assertNull($this->server->getProcess(), 'Should be null at this point');

        $server = $this->server->withThreads(1)
            ->withRoot(__DIR__ . '/resources')
            ->withRouter(__DIR__ . '/resources/index.php')
            ->runInBackground();

        $this->assertInstanceOf(Process::class, $server->getProcess());

        $server->stop();
    }
}
