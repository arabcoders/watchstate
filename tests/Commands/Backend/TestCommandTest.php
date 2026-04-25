<?php

declare(strict_types=1);

namespace Tests\Commands\Backend;

use App\Backends\Common\ClientInterface as iClient;
use App\Commands\Backend\TestCommand;
use PHPUnit\Framework\MockObject\MockObject;
use Symfony\Component\Console\Application;
use Symfony\Component\Console\Input\InputInterface as iInput;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Tester\CommandTester;

final class TestCommandTest extends \PHPUnit\Framework\TestCase
{
    public function test_lists_available_actions_from_client_interface(): void
    {
        $client = $this->createStub(iClient::class);
        $client->method('getName')->willReturn('demo');
        $client->method('getType')->willReturn('plex');

        $tester = $this->makeTester($client);
        $status = $tester->execute([
            '--select-backend' => 'demo',
            '--output' => 'json',
        ]);

        self::assertSame(TestCommand::SUCCESS, $status);

        $payload = json_decode($tester->getDisplay(), true, flags: JSON_THROW_ON_ERROR);
        $actions = array_column($payload['actions'], 'action');

        self::assertContains('getUsersList', $actions);
        self::assertContains('getPlaylistsList', $actions);
        self::assertContains('generateAccessToken', $actions);
        self::assertNotContains('withContext', $actions);
    }

    public function test_invokes_playlist_action_and_returns_result(): void
    {
        $client = $this->makeClientMock();
        $client
            ->expects(self::once())
            ->method('getPlaylistsList')
            ->with([])
            ->willReturn([
                ['id' => 'playlist-1', 'title' => 'Weekend Movies'],
            ]);

        $tester = $this->makeTester($client);
        $status = $tester->execute([
            '--select-backend' => 'demo',
            '--output' => 'json',
            'action' => 'getPlaylistsList',
        ]);

        self::assertSame(TestCommand::SUCCESS, $status);

        $payload = json_decode($tester->getDisplay(), true, flags: JSON_THROW_ON_ERROR);
        self::assertSame('getPlaylistsList', $payload['action']);
        self::assertSame([
            ['id' => 'playlist-1', 'title' => 'Weekend Movies'],
        ], $payload['result']);
    }

    public function test_invokes_action_and_routes_extra_params_into_opts(): void
    {
        $client = $this->makeClientMock();
        $client
            ->expects(self::once())
            ->method('getUsersList')
            ->with(['foo' => 'bar', 'aa' => 'ff'])
            ->willReturn([
                ['id' => 1, 'name' => 'alpha'],
            ]);

        $tester = $this->makeTester($client);
        $status = $tester->execute([
            '--select-backend' => 'demo',
            '--output' => 'json',
            'action' => 'getUsersList',
            '--param' => ['foo=bar', 'aa=ff'],
        ]);

        self::assertSame(TestCommand::SUCCESS, $status);

        $payload = json_decode($tester->getDisplay(), true, flags: JSON_THROW_ON_ERROR);
        self::assertSame('getUsersList', $payload['action']);
        self::assertSame([
            ['id' => 1, 'name' => 'alpha'],
        ], $payload['result']);
    }

    public function test_normalizes_action_name_and_passes_named_and_opts_params(): void
    {
        $client = $this->makeClientMock();
        $client
            ->expects(self::once())
            ->method('getUserToken')
            ->with(7, 'alice', [
                'PLEX_EXTERNAL_USER' => true,
                'pin' => 1234,
            ])
            ->willReturn('token-123');

        $tester = $this->makeTester($client);
        $status = $tester->execute([
            '--select-backend' => 'demo',
            '--output' => 'json',
            'action' => 'get-user-token',
            '--param' => [
                'userId=7',
                'username=alice',
                'PLEX_EXTERNAL_USER=true',
                'pin=1234',
            ],
        ]);

        self::assertSame(TestCommand::SUCCESS, $status);

        $payload = json_decode($tester->getDisplay(), true, flags: JSON_THROW_ON_ERROR);
        self::assertSame('getUserToken', $payload['action']);
        self::assertSame('token-123', $payload['result']);
    }

    public function test_inspects_action_and_shows_required_params(): void
    {
        $client = $this->createStub(iClient::class);
        $client->method('getName')->willReturn('demo');
        $client->method('getType')->willReturn('plex');

        $tester = $this->makeTester($client);
        $status = $tester->execute([
            '--select-backend' => 'demo',
            '--output' => 'json',
            '--inspect' => true,
            'action' => 'getUserToken',
        ]);

        self::assertSame(TestCommand::SUCCESS, $status);

        $payload = json_decode($tester->getDisplay(), true, flags: JSON_THROW_ON_ERROR);
        self::assertSame('getUserToken', $payload['action']);
        self::assertSame(['userId', 'username'], $payload['required_params']);
        self::assertTrue($payload['accepts_extra_params']);
        self::assertSame('opts', $payload['extra_params_target']);

        $parameters = [];
        foreach ($payload['parameters'] as $parameter) {
            $parameters[$parameter['name']] = $parameter;
        }

        self::assertSame('required', $parameters['userId']['mode']);
        self::assertSame('required', $parameters['username']['mode']);
        self::assertSame('optional', $parameters['opts']['mode']);
        self::assertStringContainsString('Receives unmatched --param keys.', $parameters['opts']['notes']);
    }

    /**
     * @return iClient&MockObject
     */
    private function makeClientMock(): iClient
    {
        $client = $this->createMock(iClient::class);
        $client->method('getName')->willReturn('demo');
        $client->method('getType')->willReturn('plex');

        return $client;
    }

    private function makeTester(iClient $client): CommandTester
    {
        $application = new Application();
        $application->getDefinition()->addOption(new InputOption('output', 'o', InputOption::VALUE_REQUIRED, '', 'table'));
        $application->addCommand($this->makeCommand($client));

        return new CommandTester($application->find(TestCommand::ROUTE));
    }

    private function makeCommand(iClient $client): TestCommand
    {
        return new class($client) extends TestCommand {
            public function __construct(
                private readonly iClient $client,
            ) {
                parent::__construct();
            }

            protected function getSelectedBackend(iInput $input): iClient
            {
                return $this->client;
            }
        };
    }
}
