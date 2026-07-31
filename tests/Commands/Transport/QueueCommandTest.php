<?php

declare(strict_types=1);

namespace Tests\Commands\Transport;

use App\API\System\TransportQueue;
use App\Commands\Transport\QueueCommand;
use App\Commands\Transport\ViewCommand;
use App\Libs\Container;
use App\Libs\Events\Queue\ArrayEventTransport;
use App\Libs\Events\Queue\EventEnvelope;
use App\Libs\Initializer;
use App\Libs\TestCase;
use Psr\Http\Message\ResponseInterface as iResponse;
use Psr\Http\Message\ServerRequestInterface as iRequest;
use Symfony\Component\Console\Application;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Tester\CommandTester;

final class QueueCommandTest extends TestCase
{
    private ArrayEventTransport $transport;

    protected function setUp(): void
    {
        parent::setUp();
        $this->initTempApp();
        $this->transport = new ArrayEventTransport();
        $this->registerInitializer();
    }

    public function test_list_table(): void
    {
        $this->transport->enqueue(EventEnvelope::create('on_webhook'));

        $tester = $this->makeTester();
        $status = $tester->execute([]);

        self::assertSame(QueueCommand::SUCCESS, $status);
        self::assertStringContainsString('Transport Queue', $tester->getDisplay());
        self::assertStringContainsString('on_webhook', $tester->getDisplay());
        self::assertStringContainsString('page 1 | per-page 25 | total 1', $tester->getDisplay());
    }

    public function test_list_json(): void
    {
        $this->transport->enqueue(EventEnvelope::create('on_push', ['ok' => true]));

        $tester = $this->makeTester();
        $status = $tester->execute(['--output' => 'json']);
        $payload = json_decode($tester->getDisplay(), true, flags: JSON_THROW_ON_ERROR);

        self::assertSame(QueueCommand::SUCCESS, $status);
        self::assertSame('on_push', ag($payload, 'items.0.event'));
        self::assertArrayNotHasKey('data', $payload['items'][0]);
        self::assertArrayNotHasKey('options', $payload['items'][0]);
        self::assertSame(1, ag($payload, 'paging.total'));
    }

    public function test_list_filter(): void
    {
        $this->transport->enqueue(EventEnvelope::create('on_webhook'));
        $this->transport->enqueue(EventEnvelope::create('on_push'));

        $tester = $this->makeTester();
        $status = $tester->execute(['--filter' => 'webhook']);

        self::assertSame(QueueCommand::SUCCESS, $status);
        self::assertStringContainsString('on_webhook', $tester->getDisplay());
        self::assertStringNotContainsString('on_push', $tester->getDisplay());
    }

    public function test_view(): void
    {
        $envelope = EventEnvelope::create('on_webhook', ['ok' => true], ['delay' => 5]);
        $this->transport->enqueue($envelope);

        $application = new Application();
        $application->getDefinition()->addOption(new InputOption('output', 'o', InputOption::VALUE_REQUIRED, '', 'table'));
        $application->addCommand(new ViewCommand());
        $tester = new CommandTester($application->find(ViewCommand::ROUTE));

        $status = $tester->execute(['id' => $envelope->id]);

        self::assertSame(ViewCommand::SUCCESS, $status);
        self::assertStringContainsString('Summary', $tester->getDisplay());
        self::assertStringContainsString('on_webhook', $tester->getDisplay());
        self::assertStringContainsString('"delay": 5', $tester->getDisplay());
    }

    public function test_invalid_page(): void
    {
        $tester = $this->makeTester();
        $status = $tester->execute(['--page' => 'nope']);

        self::assertSame(QueueCommand::FAILURE, $status);
        self::assertStringContainsString('Page must be a positive integer.', $tester->getDisplay());
    }

    public function test_invalid_state(): void
    {
        $tester = $this->makeTester();
        $status = $tester->execute(['--state' => 'unknown']);

        self::assertSame(QueueCommand::FAILURE, $status);
        self::assertStringContainsString('Unknown transport state', $tester->getDisplay());
    }

    private function makeTester(): CommandTester
    {
        $application = new Application();
        $application->getDefinition()->addOption(new InputOption('output', 'o', InputOption::VALUE_REQUIRED, '', 'table'));
        $application->addCommand(new QueueCommand());
        $application->addCommand(new ViewCommand());

        return new CommandTester($application->find(QueueCommand::ROUTE));
    }

    private function registerInitializer(): void
    {
        Container::add(Initializer::class, [
            'shared' => true,
            'class' => function () {
                return new class($this->transport) {
                    public function __construct(
                        private readonly ArrayEventTransport $transport,
                    ) {}

                    public function http(iRequest $request): iResponse
                    {
                        $path = $request->getUri()->getPath();
                        if (str_contains($path, '/system/transport/queue/') && !str_ends_with($path, '/queue/')) {
                            return new TransportQueue($this->transport)->view(basename($path));
                        }

                        return new TransportQueue($this->transport)->list($request);
                    }
                };
            },
        ]);
    }
}
