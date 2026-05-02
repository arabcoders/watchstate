<?php

declare(strict_types=1);

namespace Tests\Commands\State;

use App\Commands\State\ValidateCommand;
use App\Libs\Entity\StateInterface as iState;
use App\Libs\LogSuppressor;
use App\Libs\Mappers\ImportInterface as iImport;
use App\Libs\TestCase;
use Monolog\Logger;
use Symfony\Component\Console\Application;
use Symfony\Component\Console\Tester\CommandTester;
use Tests\Support\FakeBackendClient;
use Tests\Support\StateCommandTestSupport;

final class ValidateCommandTest extends TestCase
{
    use StateCommandTestSupport;

    public function test_remove_last_missing_record(): void
    {
        $logger = $this->initFakeBackendApp($this->fakeBackendConfig('fake_validate'));
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
