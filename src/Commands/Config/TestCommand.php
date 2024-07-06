<?php

declare(strict_types=1);

namespace App\Commands\Config;

use App\Backends\Common\ClientInterface as iClient;
use App\Backends\Common\Context;
use App\Backends\Emby\Action\GetUser as jf_GetUser;
use App\Backends\Jellyfin\Action\GetIdentifier as jf_GetIdentifier;
use App\Backends\Jellyfin\Action\GetInfo as jf_GetInfo;
use App\Backends\Jellyfin\Action\GetLibrariesList as jf_GetLibrariesList;
use App\Backends\Jellyfin\Action\GetSessions as jf_GetSessions;
use App\Backends\Jellyfin\Action\GetUsersList as jf_GetUsersList;
use App\Backends\Jellyfin\Action\GetVersion as jf_GetVersion;
use App\Backends\Plex\Action\GetIdentifier as plex_GetIdentifier;
use App\Backends\Plex\Action\GetInfo as plex_GetInfo;
use App\Backends\Plex\Action\GetLibrariesList as plex_GetLibrariesList;
use App\Backends\Plex\Action\GetSessions as plex_GetSessions;
use App\Backends\Plex\Action\GetUser as plex_GetUser;
use App\Backends\Plex\Action\GetUsersList as plex_GetUsersList;
use App\Backends\Plex\Action\GetVersion as plex_GetVersionAlias;
use App\Command;
use App\Libs\Attributes\Route\Cli;
use App\Libs\Config;
use App\Libs\ConfigFile;
use App\Libs\Container;
use App\Libs\Options;
use Closure;
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

    private iOutput|null $output = null;

    public function __construct(private iLogger $logger)
    {
        parent::__construct();
    }

    /**
     * Configure the command.
     */
    protected function configure(): void
    {
        $this->setName(self::ROUTE)
            ->setDescription('Run functional tests on the current configurations.')
            ->addOption(
                'select-backend',
                's',
                InputOption::VALUE_IS_ARRAY | InputOption::VALUE_OPTIONAL,
                'Select backend.'
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
        return $this->single(fn(): int => $this->process($input, $output), $output);
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
        $configFile = ConfigFile::open(Config::get('backends_file'), 'yaml');
        $configFile->setLogger($this->logger);

        $backends = [];
        $selected = $input->getOption('select-backend');
        $isCustom = !empty($selected) && count($selected) > 0;

        foreach ($configFile->getAll() as $backendName => $backend) {
            if ($isCustom && false === in_array($backendName, $selected)) {
                continue;
            }
            $backends[$backendName] = $backend;
        }

        foreach ($backends as $backendName => $backend) {
            $backend = $this->getBackend($backendName);
            $context = $backend->getContext();
            $output->writeln(r("Running '{client}' functional tests on '{backend}'.", [
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

    private function assert(Closure|bool|null $fn, string $message): void
    {
        $status = null === $fn ? null : (is_bool($fn) ? $fn : $fn());

        $this->output?->writeln(r('[{status}] {message}', [
            'message' => trim($message),
            'status' => null === $status ? self::SKIP : ($status ? self::OK : self::ERROR)
        ]));
    }

    private function test_plex_client(iClient $client, Context $context): void
    {
        $this->assert($client->getContext() === $context, 'getContext');
        $this->assert($client->getType() === $context->clientName, 'getType');
        $this->assert($client->getName() === $context->backendName, 'getName');

        $this->assert(Container::get(plex_GetInfo::class)($context)->isSuccessful(), 'getInfo');
        $this->assert(Container::get(plex_GetIdentifier::class)($context)->isSuccessful(), 'getIdentifier');
        $this->assert(Container::get(plex_GetLibrariesList::class)($context)->isSuccessful(), 'getLibrariesList');
        $this->assert(Container::get(plex_GetSessions::class)($context)->isSuccessful(), 'getSessions');
        $this->assert(Container::get(plex_GetUser::class)($context)->isSuccessful(), 'getUser');
        $this->assert(Container::get(plex_GetUsersList::class)($context)->isSuccessful(), 'getUsersList');
        $this->assert(Container::get(plex_GetVersionAlias::class)($context)->isSuccessful(), 'getVersion');

        // -- unimplemented tests
        $this->assert(null, 'GetLibrary');
        $this->assert(null, 'GetMetaData');
        $this->assert(null, 'GetWebUrl');
        $this->assert(null, 'Import');
        $this->assert(null, 'InspectRequest');
        $this->assert(null, 'ParseWebhook');
        $this->assert(null, 'Progress');
        $this->assert(null, 'Push');
        $this->assert(null, 'SearchId');
        $this->assert(null, 'SearchQuery');
        $this->assert(null, 'ToEntity');
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

        $this->assert(Container::get(jf_GetInfo::class)($context)->isSuccessful(), 'getInfo');
        $this->assert(Container::get(jf_GetIdentifier::class)($context)->isSuccessful(), 'getIdentifier');
        $this->assert(Container::get(jf_GetLibrariesList::class)($context)->isSuccessful(), 'getLibrariesList');
        $this->assert(Container::get(jf_GetSessions::class)($context)->isSuccessful(), 'getSessions');
        $this->assert(Container::get(jf_GetUser::class)($context)->isSuccessful(), 'getUser');
        $this->assert(Container::get(jf_GetUsersList::class)($context)->isSuccessful(), 'getUsersList with fallback');

        // -- if the token is limited and with no_fallback, the test should return false as the token doesn't have
        // -- access to the users list.
        $gul = Container::get(jf_GetUsersList::class)($context, [Options::NO_FALLBACK => true])->isSuccessful();
        $gul = ($context->isLimitedToken()) ? !(true === $gul) : $gul;
        $this->assert($gul, 'getUsersList no_fallback');

        $this->assert(Container::get(jf_GetVersion::class)($context)->isSuccessful(), 'getVersion');

        // -- unimplemented tests
        $this->assert(null, 'GetLibrary');
        $this->assert(null, 'GetMetaData');
        $this->assert(null, 'GetWebUrl');
        $this->assert(null, 'Import');
        $this->assert(null, 'InspectRequest');
        $this->assert(null, 'ParseWebhook');
        $this->assert(null, 'Progress');
        $this->assert(null, 'Push');
        $this->assert(null, 'SearchId');
        $this->assert(null, 'SearchQuery');
        $this->assert(null, 'ToEntity');
    }
}
