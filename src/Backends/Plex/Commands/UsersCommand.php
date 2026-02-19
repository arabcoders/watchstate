<?php

declare(strict_types=1);

namespace App\Backends\Plex\Commands;

use App\Backends\Plex\PlexClient;
use App\Command;
use App\Libs\Attributes\DI\Inject;
use App\Libs\Attributes\Route\Cli;
use App\Libs\LogSuppressor;
use App\Libs\Mappers\Import\DirectMapper;
use App\Libs\Mappers\ImportInterface as iImport;
use App\Libs\Options;
use App\Libs\UserContext;
use Psr\Log\LoggerInterface as iLogger;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Class UsersCommand
 *
 * This command manages Plex users.
 */
#[Cli(command: self::ROUTE)]
class UsersCommand extends Command
{
    public const string ROUTE = 'backend:plex:users';

    /**
     * Class Constructor.
     *
     * @param iImport $mapper The import interface object.
     * @param iLogger $logger The logger interface object.
     * @param LogSuppressor $suppressor The log suppressor object.
     *
     */
    public function __construct(
        #[Inject(DirectMapper::class)]
        private readonly iImport $mapper,
        private readonly iLogger $logger,
    ) {
        set_time_limit(0);
        ini_set('memory_limit', '-1');

        parent::__construct();
    }

    /**
     * Configure the method.
     */
    protected function configure(): void
    {
        $this
            ->setName(self::ROUTE)
            ->setDescription('View plex users.')
            ->addOption('no-cache', 'N', InputOption::VALUE_NONE, 'Disable cache when loading data plex.')
            ->addOption('raw', 'r', InputOption::VALUE_NONE, 'Show raw data from plex.')
            ->addOption('log', 'l', InputOption::VALUE_NONE, 'Show logs from get users list process.')
            ->addOption(
                'select-backend',
                's',
                InputOption::VALUE_IS_ARRAY | InputOption::VALUE_REQUIRED,
                'Select backend.',
            );
    }

    /**
     * Make sure the command is not running in parallel.
     *
     * @param InputInterface $input The input interface object.
     * @param OutputInterface $output The output interface object.
     *
     * @return int The status code of the command execution.
     */
    protected function runCommand(InputInterface $input, OutputInterface $output): int
    {
        $userContext = get_user_context('main', mapper: $this->mapper, logger: $this->logger);

        $backends = $input->getOption('select-backend');
        if (empty($backends)) {
            $output->writeln('<error>No backends selected. Use --select-backend option to select backend.</error>');
            return self::FAILURE;
        }

        $conf = $name = null;
        foreach ($backends as $backend) {
            if (null === ($conf = $userContext->config->get($backend))) {
                continue;
            }
            $name = $backend;
            break;
        }

        if (null === $conf) {
            $output->writeln('<error>No valid backends selected. Use --select-backend option to select backend.</error>');
            return self::FAILURE;
        }

        if (strtolower(PlexClient::CLIENT_NAME) !== ag($conf, 'type')) {
            $output->writeln('<error>Selected backend is not a plex backend. Use --select-backend option to select plex backend.</error>');
            return self::FAILURE;
        }

        $requests = $logs = $opts = [];

        if ($input->getOption('log')) {
            $opts[Options::LOG_TO_WRITER] = static function (string $log) use (&$logs) {
                $logs[] = r('[{time}] {log}', [
                    'time' => make_date(),
                    'log' => $log,
                ]);
            };
        }

        if ($input->getOption('raw')) {
            $opts[Options::NO_CACHE] = true;
            $opts[Options::RAW_RESPONSE] = true;
            $opts[Options::RAW_RESPONSE_CALLBACK] = static function (array $r) use (&$requests) {
                $requests = $r;
            };
        }

        if ($input->getOption('no-cache')) {
            $opts[Options::NO_CACHE] = true;
        }

        $backend = make_backend(backend: $conf, name: $name, options: [
            UserContext::class => $userContext,
        ]);

        $users = $backend->getUsersList($opts);

        foreach ($logs as $log) {
            $output->writeln($log);
        }

        foreach ($requests as $request) {
            $output->writeln(r('URL: {url}', ['url' => ag($request, 'url')]));

            foreach (ag($request, 'headers', []) as $key => $value) {
                $output->writeln(r('{key}: {value}', [
                    'key' => $key,
                    'value' => is_array($value) ? implode(', ', $value) : (string) $value,
                ]));
            }
            $output->writeln('');
            $output->writeln(json_encode(ag($request, 'body', []), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

            $output->writeln(str_repeat('-', 80));
        }

        $this->displayContent($users, $output, 'table');

        return self::SUCCESS;
    }
}
