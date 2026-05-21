<?php

declare(strict_types=1);

namespace Tests\Commands\State;

use App\Commands\State\ValidateCommand;
use App\Libs\Entity\StateInterface as iState;
use App\Libs\Extends\LogMessageProcessor;
use App\Libs\LogSuppressor;
use App\Libs\Mappers\ImportInterface as iImport;
use App\Libs\TestCase;
use Monolog\Handler\TestHandler;
use Monolog\Logger;
use Symfony\Component\Console\Application;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Tester\CommandTester;
use Tests\Support\FakeBackendClient;
use Tests\Support\StateCommandTestSupport;

final class ValidateCommandTest extends TestCase
{
    use StateCommandTestSupport;

    public function test_remove_last_missing_record(): void
    {
        $logger = $this->initFakeBackendApp($this->fakeBackendConfig('fake_validate'));
        $logger->pushProcessor(new LogMessageProcessor());
        $userContext = $this->makeUserContext('main', $logger);

        $entity = require __DIR__ . '/../../Fixtures/MovieEntity.php';
        $entity[iState::COLUMN_VIA] = 'fake_validate';
        $entity[iState::COLUMN_META_DATA] = [
            'fake_validate' => [
                iState::COLUMN_ID => 501,
                iState::COLUMN_TYPE => iState::TYPE_MOVIE,
                iState::COLUMN_WATCHED => 1,
                iState::COLUMN_META_DATA_ADDED_AT => 10,
                iState::COLUMN_META_DATA_PLAYED_AT => 20,
            ],
        ];

        $userContext->db->insert(new \App\Libs\Entity\StateEntity($entity));
        FakeBackendClient::setMetadataResponse('main', 'fake_validate', 501, []);

        $command = new ValidateCommand($this->createMockMapper(), $logger, new LogSuppressor([]));
        $tester = $this->makeTester($command);
        $status = $tester->execute([]);

        self::assertSame(ValidateCommand::SUCCESS, $status);
        self::assertSame(0, $userContext->db->getTotal(), 'Record should be removed when its last backend reference disappears.');
        self::assertSame([
            [
                'backend' => 'fake_validate',
                'user' => 'main',
                'id' => '501',
            ],
        ], FakeBackendClient::getCalls('metadata'));
    }

    public function test_logfile_lifecycle(): void
    {
        $logger = $this->initFakeBackendApp($this->fakeBackendConfig('fake_validate'));
        $logger->pushProcessor(new LogMessageProcessor());
        $this->migrateMainDb($logger);

        $logfile = self::$tmpPath . '/validate-log.txt';
        touch($logfile);

        $command = new ValidateCommand($this->createMockMapper(), $logger, new LogSuppressor([]));
        $tester = $this->makeTester($command);
        $status = $tester->execute([
            '--logfile' => $logfile,
        ], [
            'verbosity' => OutputInterface::VERBOSITY_VERBOSE,
        ]);

        self::assertSame(ValidateCommand::SUCCESS, $status);

        $contents = file_get_contents($logfile);
        self::assertIsString($contents);
        self::assertStringContainsString('Validation started for 1 users.', $contents);
        self::assertStringContainsString("Validating local metadata references for 'main'.", $contents);
        self::assertStringContainsString('Validation completed for 1 users in', $contents);
    }

    public function test_invalid_user_returns_failure(): void
    {
        $command = new ValidateCommand($this->createMockMapper(), new Logger('test'), new LogSuppressor([]));
        $tester = $this->makeTester($command);
        $status = $tester->execute([
            '--user' => 'ghost',
        ]);

        self::assertSame(ValidateCommand::FAILURE, $status);
        self::assertStringContainsString("User 'ghost' not found.", $tester->getDisplay());
    }

    public function test_selected_user_db_only(): void
    {
        $logger = $this->initFakeBackendApp(
            mainBackends: $this->fakeBackendConfig('fake_validate'),
            userBackends: [
                'alice' => $this->fakeBackendConfig('fake_validate'),
            ],
        );
        $logger->pushProcessor(new LogMessageProcessor());

        $mainContext = $this->makeUserContext('main', $logger);
        $aliceContext = $this->makeUserContext('alice', $logger);

        $mainEntity = require __DIR__ . '/../../Fixtures/MovieEntity.php';
        $mainEntity[iState::COLUMN_VIA] = 'fake_validate';
        $mainEntity[iState::COLUMN_META_DATA] = [
            'fake_validate' => [
                iState::COLUMN_ID => 601,
                iState::COLUMN_TYPE => iState::TYPE_MOVIE,
                iState::COLUMN_WATCHED => 1,
                iState::COLUMN_META_DATA_ADDED_AT => 10,
            ],
        ];

        $aliceEntity = $mainEntity;
        $aliceEntity[iState::COLUMN_META_DATA]['fake_validate'][iState::COLUMN_ID] = 602;

        $mainContext->db->insert(new \App\Libs\Entity\StateEntity($mainEntity));
        $aliceContext->db->insert(new \App\Libs\Entity\StateEntity($aliceEntity));

        FakeBackendClient::setMetadataResponse('main', 'fake_validate', 601, ['Id' => '601']);
        FakeBackendClient::setMetadataResponse('alice', 'fake_validate', 602, []);

        $command = new ValidateCommand($this->createMockMapper(), $logger, new LogSuppressor([]));
        $tester = $this->makeTester($command);
        $status = $tester->execute([
            '--user' => 'alice',
        ]);

        self::assertSame(ValidateCommand::SUCCESS, $status);
        self::assertSame(1, $mainContext->db->getTotal(), 'Main user records should remain untouched when another user is selected.');
        self::assertSame(0, $aliceContext->db->getTotal(), 'Selected user records should be validated and removed when metadata no longer exists.');
        self::assertSame([
            [
                'backend' => 'fake_validate',
                'user' => 'alice',
                'id' => '602',
            ],
        ], FakeBackendClient::getCalls('metadata'));
    }

    public function test_logs_failed_metadata_lookup(): void
    {
        $logger = $this->initFakeBackendApp($this->fakeBackendConfig('fake_validate'));
        $handler = new TestHandler();
        $logger->setHandlers([$handler]);
        $logger->pushProcessor(new LogMessageProcessor());
        $userContext = $this->makeUserContext('main', $logger);

        $entity = require __DIR__ . '/../../Fixtures/MovieEntity.php';
        $entity[iState::COLUMN_VIA] = 'fake_validate';
        $entity[iState::COLUMN_META_DATA] = [
            'fake_validate' => [
                iState::COLUMN_ID => 777,
                iState::COLUMN_TYPE => iState::TYPE_MOVIE,
                iState::COLUMN_WATCHED => 1,
            ],
        ];

        $userContext->db->insert(new \App\Libs\Entity\StateEntity($entity));
        FakeBackendClient::setMetadataResponse('main', 'fake_validate', 777, new \RuntimeException('boom'));

        $command = new ValidateCommand($this->createMockMapper(), $logger, new LogSuppressor([]));
        $status = $this->makeTester($command)->execute([]);

        self::assertSame(ValidateCommand::SUCCESS, $status);

        $records = array_values(array_filter(
            $handler->getRecords(),
            static fn($record): bool => 'state.validate.reference.removed' === ($record->context['event_name'] ?? null),
        ));

        self::assertNotEmpty($records);

        $match = array_values(array_filter(
            $records,
            static fn($record): bool => 'metadata_lookup_failed' === ($record->context['reason'] ?? null),
        ));

        self::assertCount(1, $match);
        self::assertSame(
            "Removing 'main@fake_validate' reference '777' from item '#1': metadata lookup failed.",
            $match[0]->message,
        );
        self::assertSame('main', $match[0]->context['user']);
        self::assertSame('fake_validate', $match[0]->context['backend']);
        self::assertSame('777', (string) $match[0]->context['item_id']);
        self::assertSame(\RuntimeException::class, $match[0]->context['error']['type']);
    }

    private function makeTester(ValidateCommand $command): CommandTester
    {
        $application = new Application();
        $application->addCommand($command);

        return new CommandTester($application->find(ValidateCommand::ROUTE));
    }

    private function createMockMapper(): iImport
    {
        return $this->createStub(iImport::class);
    }
}
