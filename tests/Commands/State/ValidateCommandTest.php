<?php

declare(strict_types=1);

namespace Tests\Commands\State;

use App\Commands\State\ValidateCommand;
use App\Libs\Entity\StateEntity;
use App\Libs\Entity\StateInterface as iState;
use App\Libs\Guid;
use App\Libs\LogSuppressor;
use App\Libs\Mappers\ImportInterface as iImport;
use App\Libs\TestCase;
use Monolog\Handler\TestHandler;
use Monolog\Level;
use Monolog\Logger;
use Symfony\Component\Console\Application;
use Symfony\Component\Console\Tester\CommandTester;
use Tests\Support\FakeBackendClient;
use Tests\Support\StateCommandTestSupport;

final class ValidateCommandTest extends TestCase
{
    use StateCommandTestSupport;

    public function test_remove_last_ref(): void
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

        $userContext->db->insert(new StateEntity($entity));
        FakeBackendClient::setPullItems('main', 'fake_validate', []);

        $command = new ValidateCommand($this->createMockMapper(), $logger, new LogSuppressor([]));
        $tester = $this->makeTester($command);
        $status = $tester->execute([]);

        self::assertSame(ValidateCommand::SUCCESS, $status);
        self::assertSame(0, $userContext->db->getTotal(), 'Record should be removed when its last backend reference disappears.');
        self::assertSame(
            [
                [
                    'backend' => 'fake_validate',
                    'user' => 'main',
                    'after' => null,
                ],
            ],
            FakeBackendClient::getCalls('pull'),
        );
        self::assertSame([], FakeBackendClient::getCalls('metadata'));
    }

    public function test_invalid_user(): void
    {
        $handler = new TestHandler();
        $logger = new Logger('test');
        $logger->pushHandler($handler);

        $command = new ValidateCommand($this->createMockMapper(), $logger, new LogSuppressor([]));
        $tester = $this->makeTester($command);
        $status = $tester->execute([
            '--user' => 'ghost',
        ]);

        self::assertSame(ValidateCommand::FAILURE, $status);
        self::assertTrue(
            $this->hasRecordWith(
                $handler,
                Level::Error,
                ['operation' => 'validate.resolve_users'],
            ),
            'Assert resolve-users error logged with operation token.',
        );
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

        $mainContext->db->insert(new StateEntity($mainEntity));
        $aliceContext->db->insert(new StateEntity($aliceEntity));

        FakeBackendClient::setPullItems('main', 'fake_validate', [['Id' => '601']]);
        FakeBackendClient::setPullItems('alice', 'fake_validate', []);

        $command = new ValidateCommand($this->createMockMapper(), $logger, new LogSuppressor([]));
        $tester = $this->makeTester($command);
        $status = $tester->execute([
            '--user' => 'alice',
        ]);

        self::assertSame(ValidateCommand::SUCCESS, $status);
        self::assertSame(1, $mainContext->db->getTotal(), 'Main user records should remain untouched when another user is selected.');
        self::assertSame(
            0,
            $aliceContext->db->getTotal(),
            'Selected user records should be validated and removed when metadata no longer exists.',
        );
        self::assertSame(
            [
                [
                    'backend' => 'fake_validate',
                    'user' => 'alice',
                    'after' => null,
                ],
            ],
            FakeBackendClient::getCalls('pull'),
        );
        self::assertSame([], FakeBackendClient::getCalls('metadata'));
    }

    public function test_bulk_mixed_refs(): void
    {
        $logger = $this->initFakeBackendApp($this->fakeBackendConfig('fake_validate'));
        $userContext = $this->makeUserContext('main', $logger);

        $entity = require __DIR__ . '/../../Fixtures/MovieEntity.php';
        $entity[iState::COLUMN_VIA] = 'fake_validate';

        foreach ([701, 702, 703] as $id) {
            $entity[iState::COLUMN_GUIDS] = [Guid::GUID_IMDB => 'tt' . $id];
            $entity[iState::COLUMN_META_DATA] = [
                'fake_validate' => [
                    iState::COLUMN_ID => $id,
                    iState::COLUMN_TYPE => iState::TYPE_MOVIE,
                    iState::COLUMN_WATCHED => 1,
                    iState::COLUMN_META_DATA_ADDED_AT => 10,
                ],
            ];
            $userContext->db->insert(new StateEntity($entity));
        }

        FakeBackendClient::setPullItems('main', 'fake_validate', [
            ['ratingKey' => '701'],
            ['Id' => '702'],
        ]);

        $command = new ValidateCommand($this->createMockMapper(), $logger, new LogSuppressor([]));
        $status = $this->makeTester($command)->execute([]);

        self::assertSame(ValidateCommand::SUCCESS, $status);
        self::assertSame(2, $userContext->db->getTotal());
        self::assertNull($userContext->db->findByBackendId('fake_validate', 703));

        foreach ([701, 702] as $id) {
            $retained = $userContext->db->findByBackendId('fake_validate', $id);
            self::assertInstanceOf(StateEntity::class, $retained);
            self::assertSame(1, $retained->watched);
            self::assertSame(1, $retained->updated);
            self::assertSame($id, $retained->getMetadata()['fake_validate'][iState::COLUMN_ID]);
        }

        self::assertSame(
            [['backend' => 'fake_validate', 'user' => 'main', 'after' => null]],
            FakeBackendClient::getCalls('pull'),
        );
        self::assertSame([], FakeBackendClient::getCalls('metadata'));
    }

    public function test_dry_run(): void
    {
        $logger = $this->initFakeBackendApp($this->fakeBackendConfig('fake_validate'));
        $userContext = $this->makeUserContext('main', $logger);
        $entity = require __DIR__ . '/../../Fixtures/MovieEntity.php';
        $entity[iState::COLUMN_VIA] = 'fake_validate';

        foreach ([
            [],
            ['fake_validate' => [iState::COLUMN_ID => 801]],
            ['fake_validate' => [iState::COLUMN_ID => 802], 'deleted_backend' => [iState::COLUMN_ID => 803]],
        ] as $index => $metadata) {
            $entity[iState::COLUMN_GUIDS] = [Guid::GUID_IMDB => 'tt-dry-' . $index];
            $entity[iState::COLUMN_META_DATA] = $metadata;
            $userContext->db->insert(new StateEntity($entity));
        }

        FakeBackendClient::setPullItems('main', 'fake_validate', [['Id' => '802']]);
        $before = array_map(static fn(iState $item): array => $item->getAll(), iterator_to_array($userContext->db->fetch()));
        $command = new ValidateCommand($this->createMockMapper(), $logger, new LogSuppressor([]));
        $tester = $this->makeTester($command);

        self::assertSame(ValidateCommand::SUCCESS, $tester->execute(['--dry-run' => true]));
        self::assertSame(
            $before,
            array_map(static fn(iState $item): array => $item->getAll(), iterator_to_array($userContext->db->fetch())),
        );
        self::assertStringContainsString('User: main (dry run)', $tester->getDisplay());
        self::assertStringContainsString('Reference To Remove: 1', $tester->getDisplay());

        self::assertSame(ValidateCommand::SUCCESS, $tester->execute([]));
        self::assertSame(1, $userContext->db->getTotal());
        $retained = $userContext->db->findByBackendId('fake_validate', 802);
        self::assertInstanceOf(StateEntity::class, $retained);
        self::assertSame(['fake_validate' => [iState::COLUMN_ID => 802]], $retained->getMetadata());
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
