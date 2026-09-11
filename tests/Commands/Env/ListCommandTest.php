<?php

declare(strict_types=1);

namespace Tests\Commands\Env;

use App\API\System\Env;
use App\Commands\Env\ListCommand;
use App\Libs\Config;
use App\Libs\Container;
use App\Libs\EnvFile;
use App\Libs\Initializer;
use App\Libs\TestCase;
use Psr\Http\Message\ResponseInterface as iResponse;
use Psr\Http\Message\ServerRequestInterface as iRequest;
use Symfony\Component\Console\Application;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Tester\CommandTester;

final class ListCommandTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->initTempApp();
        Container::add(Initializer::class, [
            'shared' => true,
            'class' => static fn() => new class {
                public function http(iRequest $request): iResponse
                {
                    return new Env()->envList($request);
                }
            },
        ]);
    }

    public function test_key_filter(): void
    {
        $tester = $this->makeTester();
        $status = $tester->execute([
            '--key' => ['ws_db_mode', 'WS_LOGGER_ACCESS_LEVEL'],
            '--json' => true,
        ]);

        self::assertSame(ListCommand::SUCCESS, $status);
        $payload = json_decode($tester->getDisplay(), true, flags: JSON_THROW_ON_ERROR);
        self::assertSame(['WS_DB_MODE', 'WS_LOGGER_ACCESS_LEVEL'], array_column($payload['data'], 'key'));
    }

    public function test_key_short(): void
    {
        $tester = $this->makeTester();
        $status = $tester->execute(['--key' => ['db_mode'], '--json' => true]);

        self::assertSame(ListCommand::SUCCESS, $status);
        $payload = json_decode($tester->getDisplay(), true, flags: JSON_THROW_ON_ERROR);
        self::assertSame(['WS_DB_MODE'], array_column($payload['data'], 'key'));
    }

    public function test_key_partial(): void
    {
        $tester = $this->makeTester();
        $status = $tester->execute(['--key' => ['logger_access'], '--json' => true]);

        self::assertSame(ListCommand::SUCCESS, $status);
        $payload = json_decode($tester->getDisplay(), true, flags: JSON_THROW_ON_ERROR);
        self::assertSame(
            ['WS_LOGGER_ACCESS_ENABLE', 'WS_LOGGER_ACCESS_FORMAT', 'WS_LOGGER_ACCESS_LEVEL'],
            array_column($payload['data'], 'key'),
        );
    }

    public function test_key_glob(): void
    {
        $tester = $this->makeTester();
        $status = $tester->execute(['--key' => ['cron*'], '--json' => true]);

        self::assertSame(ListCommand::SUCCESS, $status);
        $payload = json_decode($tester->getDisplay(), true, flags: JSON_THROW_ON_ERROR);
        self::assertNotEmpty($payload['data']);
        foreach (array_column($payload['data'], 'key') as $key) {
            self::assertStringStartsWith('WS_CRON', $key);
        }
    }

    public function test_key_missing(): void
    {
        $tester = $this->makeTester();
        $status = $tester->execute(['--key' => ['WS_NOT_REAL']]);

        self::assertSame(ListCommand::SUCCESS, $status);
        self::assertStringContainsString('No environment keys matched.', $tester->getDisplay());
    }

    public function test_key(): void
    {
        $tester = $this->makeTester();
        $status = $tester->execute(['--key' => ['WS_DB_MODE']]);

        self::assertSame(ListCommand::SUCCESS, $status);
        self::assertStringContainsString("1. WS_DB_MODE\n   value: ", $tester->getDisplay());
        self::assertStringContainsString('description: DB journal mode.', $tester->getDisplay());
    }

    public function test_array_value(): void
    {
        $tester = $this->makeTester();
        $status = $tester->execute(['--key' => ['trust_local_net']]);

        self::assertSame(ListCommand::SUCCESS, $status);
        self::assertStringContainsString('1. WS_TRUST_LOCAL_NET', $tester->getDisplay());
        self::assertStringContainsString(
            'value: ["192.168.0.0/16","127.0.0.1/32","10.0.0.0/8","::1/128","172.16.0.0/12"]',
            $tester->getDisplay(),
        );
    }

    public function test_key_empty(): void
    {
        $tester = $this->makeTester();
        $status = $tester->execute(['--key' => ['']]);

        self::assertSame(ListCommand::FAILURE, $status);
        self::assertStringContainsString('Environment key filter cannot be empty.', $tester->getDisplay());
    }

    public function test_key_set_mask(): void
    {
        new EnvFile((string) Config::get('path') . '/config/.env', create: true)
            ->set('WS_CACHE_URL', 'redis://secret')
            ->persist();

        $tester = $this->makeTester();
        $status = $tester->execute([
            '--key' => ['WS_CACHE_URL', 'WS_DB_MODE'],
            '--set' => true,
            '--json' => true,
        ]);

        self::assertSame(ListCommand::SUCCESS, $status);
        $payload = json_decode($tester->getDisplay(), true, flags: JSON_THROW_ON_ERROR);
        self::assertSame(['WS_CACHE_URL'], array_column($payload['data'], 'key'));
        self::assertSame('*HIDDEN*', $payload['data'][0]['value']);

        $tester = $this->makeTester();
        $status = $tester->execute([
            '--key' => ['WS_CACHE_URL'],
            '--set' => true,
            '--expose' => true,
            '--json' => true,
        ]);

        self::assertSame(ListCommand::SUCCESS, $status);
        $payload = json_decode($tester->getDisplay(), true, flags: JSON_THROW_ON_ERROR);
        self::assertSame('redis://secret', $payload['data'][0]['value']);
    }

    private function makeTester(): CommandTester
    {
        $application = new Application();
        $application->getDefinition()->addOption(new InputOption('json', null, InputOption::VALUE_NONE));
        $application->addCommand(new ListCommand());

        return new CommandTester($application->find(ListCommand::ROUTE));
    }
}
