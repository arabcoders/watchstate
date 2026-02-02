<?php

declare(strict_types=1);

namespace App\Commands\Config;

use App\Backends\Common\ClientInterface as iClient;
use App\Backends\Common\Context;
use App\Backends\Emby\Action\GetUser as jf_GetUser;
use App\Backends\Jellyfin\Action\GetIdentifier as jf_GetIdentifier;
use App\Backends\Jellyfin\Action\GetInfo as jf_GetInfo;
use App\Backends\Jellyfin\Action\GetLibrariesList as jf_GetLibrariesList;
use App\Backends\Jellyfin\Action\GetLibrary as jf_GetLibrary;
use App\Backends\Jellyfin\Action\GetMetaData as jf_GetMetaData;
use App\Backends\Jellyfin\Action\GetSessions as jf_GetSessions;
use App\Backends\Jellyfin\Action\GetUsersList as jf_GetUsersList;
use App\Backends\Jellyfin\Action\GetVersion as jf_GetVersion;
use App\Backends\Jellyfin\Action\GetWebUrl as jf_GetWebUrl;
use App\Backends\Jellyfin\Action\SearchId as jf_SearchId;
use App\Backends\Jellyfin\Action\SearchQuery as jf_SearchQuery;
use App\Backends\Jellyfin\Action\ToEntity as jf_ToEntity;
use App\Backends\Plex\Action\GetIdentifier as plex_GetIdentifier;
use App\Backends\Plex\Action\GetInfo as plex_GetInfo;
use App\Backends\Plex\Action\GetLibrariesList as plex_GetLibrariesList;
use App\Backends\Plex\Action\GetLibrary as plex_GetLibrary;
use App\Backends\Plex\Action\GetMetaData as plex_GetMetaData;
use App\Backends\Plex\Action\GetSessions as plex_GetSessions;
use App\Backends\Plex\Action\GetUser as plex_GetUser;
use App\Backends\Plex\Action\GetUsersList as plex_GetUsersList;
use App\Backends\Plex\Action\GetVersion as plex_GetVersion;
use App\Backends\Plex\Action\GetWebUrl as plex_GetWebUrl;
use App\Backends\Plex\Action\SearchId as plex_SearchId;
use App\Backends\Plex\Action\SearchQuery as plex_SearchQuery;
use App\Backends\Plex\Action\ToEntity as plex_ToEntity;
use App\Command;
use App\Libs\Attributes\Route\Cli;
use App\Libs\Config;
use App\Libs\ConfigFile;
use App\Libs\Container;
use App\Libs\Entity\StateInterface as iState;
use App\Libs\Options;
use Closure;
use InvalidArgumentException;
use Monolog\Level;
use Psr\Log\LoggerInterface as iLogger;
use Symfony\Component\Console\Input\InputInterface as iInput;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface as iOutput;

/**
 * Class CheckCommand
 *
 * Perform a check on all possible actions.
 */
#[Cli(command: self::ROUTE)]
final class TestCommand extends Command
{
    public const string ROUTE = 'config:test';

    private const string OK = '<info>OK</info>';

    private const string ERROR = '<error>FA</error>';

    private const string SKIP = '<notice>SK</notice>';

    private bool $runExtended = false;

    private ?iOutput $output = null;

    public function __construct(
        private iLogger $logger,
    ) {
        parent::__construct();
    }

    /**
     * Configure the command.
     */
    protected function configure(): void
    {
        $this
            ->setName(self::ROUTE)
            ->setDescription('Run functional tests on the current configurations.')
            ->addOption(
                'select-backend',
                's',
                InputOption::VALUE_IS_ARRAY | InputOption::VALUE_OPTIONAL,
                'Select backend.',
            )
            ->addOption(
                'run-extended',
                'e',
                InputOption::VALUE_NONE,
                'Run extended tests. It will be slower as it will test all possible actions.',
            )
            ->setHelp(
                <<<HELP

                    This command will check all possible actions against the current configurations.
                    It will check whether we are able to execute the actions in dry mode of course
                    for state changing requests.

                    HELP,
            );
    }

    /**
     * Make sure the command is not running in parallel.
     *
     * @param iInput $input The input interface instance.
     * @param iOutput $output The output interface instance.
     *
     * @return int The exit code of the command.
     */
    protected function runCommand(iInput $input, iOutput $output): int
    {
        return $this->single(fn(): int => $this->process($input, $output), $output, [
            iLogger::class => $this->logger,
            Level::class => Level::Error,
        ]);
    }

    /**
     * Executes a command.
     *
     * @param iInput $input The input object used for retrieving the command arguments and options.
     * @param iOutput $output The output object used for displaying messages.
     *
     * @return int The exit code of the command execution. Returns "SUCCESS" constant value.
     */
    protected function process(iInput $input, iOutput $output): int
    {
        $this->output = $output;
        $this->runExtended = (bool) $input->getOption('run-extended');

        $configFile = ConfigFile::open(Config::get('backends_file'), 'yaml');
        $configFile->setLogger($this->logger);

        $backends = [];
        $selected = $input->getOption('select-backend');
        $isCustom = !empty($selected) && count($selected) > 0;

        foreach ($configFile->getAll() as $backendName => $backend) {
            if ($isCustom && false === in_array($backendName, $selected, true)) {
                continue;
            }
            $backends[$backendName] = $backend;
        }

        foreach ($backends as $backendName => $backend) {
            $backend = $this->getBackend($backendName);
            $context = $backend->getContext();
            $output->writeln(r("Running '{client}' client functional tests on '{backend}'.", [
                'client' => $context->clientName,
                'backend' => $context->backendName,
            ]));
            switch ($context->clientName) {
                case 'Emby':
                    $this->test_emby_client($backend, $context);
                    break;
                case 'Jellyfin':
                    $this->test_jellyfin_client($backend, $context);
                    break;
                case 'Plex':
                    $this->test_plex_client($backend, $context);
                    break;
                default:
                    $output->writeln(r("Unknown client '{client}'.", ['client' => $context->clientName]));
                    break;
            }
        }

        return self::SUCCESS;
    }

    private function assert(Closure|bool|null $fn, string $message = ''): void
    {
        if (empty($message)) {
            if (false === $fn instanceof Closure) {
                throw new InvalidArgumentException(
                    'When no message is provided, the first argument must be a closure.',
                );
            }
            $fn();
            return;
        }

        if (null === $fn) {
            $status = null;
        } elseif (is_bool($fn)) {
            $status = $fn;
        } else {
            $status = $fn();
        }

        $statusLabel = match ($status) {
            null => self::SKIP,
            true => self::OK,
            default => self::ERROR,
        };
        $this->output?->writeln(
            r('[{status}] {message}', [
                'message' => trim($message),
                'status' => $statusLabel,
            ]),
            null === $status ? iOutput::VERBOSITY_VERY_VERBOSE : iOutput::OUTPUT_NORMAL,
        );
    }

    private function test_plex_client(iClient $client, Context $context): void
    {
        $this->assert($client->getContext() === $context, 'getContext');
        $this->assert($client->getType() === $context->clientName, 'getType');
        $this->assert($client->getName() === $context->backendName, 'getName');

        $getInfo = Container::get(plex_GetInfo::class)($context);
        $this->assert($getInfo->isSuccessful(), 'getInfo');
        if (false === $getInfo->isSuccessful()) {
            $this->output->writeln(r('<error>{error}</error>', [
                'error' => $getInfo->hasError() ? $getInfo->error->format() : '',
            ]), iOutput::VERBOSITY_VERBOSE);
        }
        $getIdentifier = Container::get(plex_GetIdentifier::class)($context);
        $this->assert($getIdentifier->isSuccessful(), 'getIdentifier');
        if (false === $getIdentifier->isSuccessful()) {
            $this->output->writeln(r('<error>{error}</error>', [
                'error' => $getIdentifier->hasError() ? $getIdentifier->error->format() : '',
            ]), iOutput::VERBOSITY_VERBOSE);
        }

        $getSessions = Container::get(plex_GetSessions::class)($context);
        $this->assert($getSessions->isSuccessful(), 'getSessions');
        if (false === $getSessions->isSuccessful()) {
            $this->output->writeln(r('<error>{error}</error>', [
                'error' => $getSessions->hasError() ? $getSessions->error->format() : '',
            ]), iOutput::VERBOSITY_VERBOSE);
        }

        $getUser = Container::get(plex_GetUser::class)($context);
        $this->assert($getUser->isSuccessful(), 'getUser');
        if (false === $getUser->isSuccessful()) {
            $this->output->writeln(r('<error>{error}</error>', [
                'error' => $getUser->hasError() ? $getUser->error->format() : '',
            ]), iOutput::VERBOSITY_VERBOSE);
        }

        $getUsersList = Container::get(plex_GetUsersList::class)($context);
        $this->assert($getUsersList->isSuccessful(), 'getUsersList');
        if (false === $getUsersList->isSuccessful()) {
            $this->output->writeln(r('<error>{error}</error>', [
                'error' => $getUsersList->hasError() ? $getUsersList->error->format() : '',
            ]), iOutput::VERBOSITY_VERBOSE);
        }

        $getVersion = Container::get(plex_GetVersion::class)($context);
        $this->assert($getVersion->isSuccessful(), 'getVersion');
        if (false === $getVersion->isSuccessful()) {
            $this->output->writeln(r('<error>{error}</error>', [
                'error' => $getVersion->hasError() ? $getVersion->error->format() : '',
            ]), iOutput::VERBOSITY_VERBOSE);
        }

        $this->assert(function () use ($context, $client) {
            $libraries = Container::get(plex_GetLibrariesList::class)($context, [Options::NO_CACHE => true]);
            $this->assert($libraries->isSuccessful(), 'GetLibrariesList');

            if (false === $libraries->isSuccessful()) {
                $this->output->writeln(r('<error>{error}</error>', [
                    'error' => $libraries->hasError() ? $libraries->error->format() : '',
                ]), iOutput::VERBOSITY_VERBOSE);
                return false;
            }

            // -- get first library that isn't ignored and is supported.
            $lib = current(array_filter(
                $libraries->response,
                static fn($i) => true === (bool) ag($i, 'supported', false) && false === (bool) ag($i, 'ignored'),
            ));

            if (empty($lib)) {
                $this->assert(null, 'GetLibrary');
                return $libraries->isSuccessful();
            }

            $library = Container::get(plex_GetLibrary::class)($context, $client->getGuid(), ag($lib, 'id'), [
                Options::LIMIT_RESULTS => 10,
                Options::NO_CACHE => true,
            ]);

            $this->assert($library->isSuccessful(), 'GetLibrary');

            if (false === $library->isSuccessful()) {
                $this->output->writeln(r('<error>{error}</error>', [
                    'error' => $libraries->hasError() ? $libraries->error->format() : '',
                ]), iOutput::VERBOSITY_VERBOSE);
                return false;
            }

            $item = current($library->response);
            if (empty($item)) {
                $this->assert(null, 'GetMetaData');
            }

            $item = Container::get(plex_GetMetaData::class)($context, ag($item, 'id'));
            $this->assert($item->isSuccessful(), 'GetMetaData');

            if (false === $item->isSuccessful()) {
                $this->output->writeln(r('<error>{error}</error>', [
                    'error' => $item->hasError() ? $item->error->format() : '',
                ]), iOutput::VERBOSITY_VERBOSE);
                return false;
            }

            $toEntity = Container::get(plex_ToEntity::class)(
                $context,
                ag($item->response, 'MediaContainer.Metadata.0'),
            );
            $this->assert($toEntity->isSuccessful(), 'ToEntity');
            if (false === $toEntity->isSuccessful()) {
                $this->output->writeln(r('<error>{error}</error>', [
                    'error' => $toEntity->hasError() ? $toEntity->error->format() : '',
                ]), iOutput::VERBOSITY_VERBOSE);
                return false;
            }

            $entity = $toEntity->response;
            assert($entity instanceof iState, 'Expected state entity from Plex metadata.');

            $itemId = ag($entity->getMetadata($entity->via), iState::COLUMN_ID);

            $getWebUrl = Container::get(plex_GetWebUrl::class)($context, $entity->type, $itemId);
            $this->assert($getWebUrl->isSuccessful(), 'GetWebUrl');
            if (false === $getWebUrl->isSuccessful()) {
                $this->output->writeln(r('<error>{error}</error>', [
                    'error' => $getWebUrl->hasError() ? $getWebUrl->error->format() : '',
                ]), iOutput::VERBOSITY_VERBOSE);
            }
            $searchId = Container::get(plex_SearchId::class)($context, $itemId);
            $this->assert($searchId->isSuccessful(), 'SearchId');
            if (false === $searchId->isSuccessful()) {
                $this->output->writeln(r('<error>{error}</error>', [
                    'error' => $searchId->hasError() ? $searchId->error->format() : '',
                ]), iOutput::VERBOSITY_VERBOSE);
            }

            $searchQuery = Container::get(plex_SearchQuery::class)($context, $entity->title, 1);
            $this->assert($searchQuery->isSuccessful(), 'SearchQuery');
            if (false === $searchQuery->isSuccessful()) {
                $this->output->writeln(r('<error>{error}</error>', [
                    'error' => $searchQuery->hasError() ? $searchQuery->error->format() : '',
                ]), iOutput::VERBOSITY_VERBOSE);
            }

            return true;
        });

        if (false === $this->runExtended) {
            return;
        }

        // -- unimplemented tests
        $this->assert(null, 'Import');
        $this->assert(null, 'Export');
        $this->assert(null, 'Backup');
        $this->assert(null, 'InspectRequest');
        $this->assert(null, 'ParseWebhook');
        $this->assert(null, 'Progress');
        $this->assert(null, 'Push');
    }

    private function test_emby_client(iClient $client, Context $context): void
    {
        $this->test_jellyfin_client($client, $context);
    }

    private function test_jellyfin_client(iClient $client, Context $context): void
    {
        $this->assert($client->getContext() === $context, 'getContext');
        $this->assert($client->getType() === $context->clientName, 'getType');
        $this->assert($client->getName() === $context->backendName, 'getName');

        $getInfo = Container::get(jf_GetInfo::class)($context);
        $this->assert($getInfo->isSuccessful(), 'getInfo');
        if (false === $getInfo->isSuccessful()) {
            $this->output->writeln(r('<error>{error}</error>', [
                'error' => $getInfo->hasError() ? $getInfo->error->format() : '',
            ]), iOutput::VERBOSITY_VERBOSE);
        }

        $getIdentifier = Container::get(jf_GetIdentifier::class)($context);
        $this->assert($getIdentifier->isSuccessful(), 'getIdentifier');
        if (false === $getIdentifier->isSuccessful()) {
            $this->output->writeln(r('<error>{error}</error>', [
                'error' => $getIdentifier->hasError() ? $getIdentifier->error->format() : '',
            ]), iOutput::VERBOSITY_VERBOSE);
        }

        $getSessions = Container::get(jf_GetSessions::class)($context);
        $this->assert($getSessions->isSuccessful(), 'getSessions');
        if (false === $getSessions->isSuccessful()) {
            $this->output->writeln(r('<error>{error}</error>', [
                'error' => $getSessions->hasError() ? $getSessions->error->format() : '',
            ]), iOutput::VERBOSITY_VERBOSE);
        }

        $getUser = Container::get(jf_GetUser::class)($context);
        $this->assert($getUser->isSuccessful(), 'getUser');
        if (false === $getUser->isSuccessful()) {
            $this->output->writeln(r('<error>{error}</error>', [
                'error' => $getUser->hasError() ? $getUser->error->format() : '',
            ]), iOutput::VERBOSITY_VERBOSE);
        }

        $getUsersWithFallBack = Container::get(jf_GetUsersList::class)($context);
        $this->assert($getUsersWithFallBack->isSuccessful(), 'getUsersList with fallback');
        if (false === $getUsersWithFallBack->isSuccessful()) {
            $this->output->writeln(r('<error>{error}</error>', [
                'error' => $getUsersWithFallBack->hasError() ? $getUsersWithFallBack->error->format() : '',
            ]), iOutput::VERBOSITY_VERBOSE);
        }

        // -- if the token is limited and with no_fallback, the test should return false as the token doesn't have
        // -- access to the users list.
        $getUsersWithOutFallBack = Container::get(jf_GetUsersList::class)($context, [
            Options::NO_FALLBACK => true,
        ]);
        $gul_status = $context->isLimitedToken()
            ? !(true === $getUsersWithOutFallBack->isSuccessful())
            : $getUsersWithOutFallBack->isSuccessful();
        $this->assert($gul_status, 'getUsersList with no fallback');
        if (false === $gul_status) {
            $this->output->writeln(r('<error>{error}</error>', [
                'error' => $getUsersWithOutFallBack->hasError() ? $getUsersWithOutFallBack->error->format() : '',
            ]), iOutput::VERBOSITY_VERBOSE);
        }

        $getVersion = Container::get(jf_GetVersion::class)($context);
        $this->assert($getVersion->isSuccessful(), 'getVersion');
        if (false === $getVersion->isSuccessful()) {
            $this->output->writeln(r('<error>{error}</error>', [
                'error' => $getVersion->hasError() ? $getVersion->error->format() : '',
            ]), iOutput::VERBOSITY_VERBOSE);
        }

        // -- Library related tests, they are grouped as they need each other to run.
        $this->assert(function () use ($context, $client) {
            $libraries = Container::get(jf_GetLibrariesList::class)($context, [Options::NO_CACHE => true]);
            $status = $libraries->isSuccessful();

            $this->assert($status, 'GetLibrariesList');

            if (false === $status) {
                $this->output->writeln(r('<error>{error}</error>', [
                    'error' => $libraries->hasError() ? $libraries->error->format() : '',
                ]), iOutput::VERBOSITY_VERBOSE);
                return false;
            }

            // -- get first library that isn't ignored and is supported.
            $lib = current(array_filter(
                $libraries->response,
                static fn($i) => true === (bool) ag($i, 'supported', false) && false === (bool) ag($i, 'ignored'),
            ));

            if (empty($lib)) {
                $this->assert(null, 'GetLibrary');
                return false;
            }

            $library = Container::get(jf_GetLibrary::class)($context, $client->getGuid(), ag($lib, 'id'), [
                Options::LIMIT_RESULTS => 10,
            ]);

            $this->assert($library->isSuccessful(), 'GetLibrary');

            if (false === $library->isSuccessful()) {
                $this->output->writeln(r('<error>{error}</error>', [
                    'error' => $library->hasError() ? $library->error->format() : '',
                ]), iOutput::VERBOSITY_VERBOSE);
                return false;
            }

            $item = current($library->response);
            if (empty($item)) {
                $this->assert(null, 'GetMetaData');
                return false;
            }

            $item = Container::get(jf_GetMetaData::class)($context, ag($item, 'id'));
            $this->assert($item->isSuccessful(), 'GetMetaData');

            if (false === $item->isSuccessful()) {
                $this->output->writeln(r('<error>{error}</error>', [
                    'error' => $item->hasError() ? $item->error->format() : '',
                ]), iOutput::VERBOSITY_VERBOSE);
                return false;
            }

            $toEntity = Container::get(jf_ToEntity::class)($context, $item->response);
            $this->assert($toEntity->isSuccessful(), 'ToEntity');

            if (false === $toEntity->isSuccessful()) {
                $this->output->writeln(r('<error>{error}</error>', [
                    'error' => $toEntity->hasError() ? $toEntity->error->format() : '',
                ]), iOutput::VERBOSITY_VERBOSE);
                return false;
            }

            $entity = $toEntity->response;
            assert($entity instanceof iState, 'Expected state entity from Jellyfin metadata.');

            $itemId = ag($entity->getMetadata($entity->via), iState::COLUMN_ID);

            $getWebUrl = Container::get(jf_GetWebUrl::class)($context, $entity->type, $itemId);
            $this->assert($getWebUrl->isSuccessful(), 'GetWebUrl');
            if (false === $getWebUrl->isSuccessful()) {
                $this->output->writeln(r('<error>{error}</error>', [
                    'error' => $getWebUrl->hasError() ? $getWebUrl->error->format() : '',
                ]), iOutput::VERBOSITY_VERBOSE);
            }

            $searchId = Container::get(jf_SearchId::class)($context, ag($item->response, 'Id'));
            $this->assert($searchId->isSuccessful(), 'SearchId');
            if (false === $searchId->isSuccessful()) {
                $this->output->writeln(r('<error>{error}</error>', [
                    'error' => $searchId->hasError() ? $searchId->error->format() : '',
                ]), iOutput::VERBOSITY_VERBOSE);
            }

            $searchQuery = Container::get(jf_SearchQuery::class)($context, $entity->title, 1);
            $this->assert($searchQuery->isSuccessful(), 'SearchQuery');
            if (false === $searchQuery->isSuccessful()) {
                $this->output->writeln(r('<error>{error}</error>', [
                    'error' => $searchQuery->hasError() ? $searchQuery->error->format() : '',
                ]), iOutput::VERBOSITY_VERBOSE);
            }

            return true;
        });

        if (false === $this->runExtended) {
            return;
        }

        // -- unimplemented tests
        $this->assert(null, 'Import');
        $this->assert(null, 'Export');
        $this->assert(null, 'Backup');
        $this->assert(null, 'InspectRequest');
        $this->assert(null, 'ParseWebhook');
        $this->assert(null, 'Progress');
        $this->assert(null, 'Push');
    }
}
