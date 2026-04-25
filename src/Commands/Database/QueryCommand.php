<?php

declare(strict_types=1);

namespace App\Commands\Database;

use App\Command;
use App\Libs\Attributes\DI\Inject;
use App\Libs\Attributes\Route\Cli;
use App\Libs\Mappers\Import\DirectMapper;
use App\Libs\Mappers\ImportInterface as iImport;
use App\Libs\UserContext;
use PDO;
use Psr\Log\LoggerInterface as iLogger;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface as iInput;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface as iOutput;
use Throwable;

#[Cli(command: self::ROUTE)]
class QueryCommand extends Command
{
    public const string ROUTE = 'db:query';

    public function __construct(
        #[Inject(DirectMapper::class)]
        protected iImport $mapper,
        protected iLogger $logger,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->setName(self::ROUTE)
            ->setDescription('Execute SQL against the selected user database.')
            ->addOption('user', 'u', InputOption::VALUE_REQUIRED, 'Select user.', 'main')
            ->addOption(
                'param',
                'p',
                InputOption::VALUE_IS_ARRAY | InputOption::VALUE_REQUIRED,
                'Bind SQL parameter. Named placeholders use key=value. Positional placeholders treat each value literally.',
            )
            ->addArgument('sql', InputArgument::REQUIRED, 'SQL statement to execute.');
    }

    protected function runCommand(iInput $input, iOutput $output): int
    {
        $mode = strtolower((string) $input->getOption('output'));
        if (!in_array($mode, self::DISPLAY_OUTPUT, true)) {
            $mode = 'table';
        }

        $sql = trim((string) $input->getArgument('sql'));

        try {
            if ('' === $sql) {
                throw new \InvalidArgumentException('SQL statement cannot be empty.');
            }

            $userContext = $this->getUserContext((string) $input->getOption('user'));
            $statement = $userContext->db->getDBLayer()->prepare($sql);
            $statement->execute($this->parseParameters($sql, (array) $input->getOption('param')));

            if ($statement->columnCount() > 0) {
                $rows = $statement->fetchAll(PDO::FETCH_ASSOC);

                if ([] === $rows && 'table' === $mode) {
                    $output->writeln('<comment>No rows returned.</comment>');
                    return self::SUCCESS;
                }

                $this->displayContent($rows, $output, $mode);
                return self::SUCCESS;
            }

            $output->writeln(r('<info>Affected {count} row(s).</info>', [
                'count' => $statement->rowCount(),
            ]));

            return self::SUCCESS;
        } catch (Throwable $e) {
            $output->writeln(r('<error>{kind}: {message}</error>', [
                'kind' => $e::class,
                'message' => $e->getMessage(),
            ]));

            return self::FAILURE;
        }
    }

    protected function getUserContext(string $user): UserContext
    {
        return get_user_context(user: $user, mapper: $this->mapper, logger: $this->logger);
    }

    /**
     * @param array<int,string> $items
     *
     * @return array<int|string,mixed>
     */
    protected function parseParameters(string $sql, array $items): array
    {
        $usesNamed = $this->hasNamedPlaceholders($sql);
        $usesPositional = $this->hasPositionalPlaceholders($sql);

        if (true === $usesNamed && true === $usesPositional) {
            throw new \InvalidArgumentException('Mixed named and positional SQL placeholders are not supported.');
        }

        $params = [];

        foreach ($items as $item) {
            if (true === $usesNamed) {
                $pair = explode('=', $item, 2);

                if (2 !== count($pair) || '' === trim($pair[0])) {
                    throw new \InvalidArgumentException(
                        r("Invalid named SQL parameter '{item}'. Expected key=value.", ['item' => $item]),
                    );
                }

                [$key, $value] = $pair;
                $key = trim($key);
                $params[$this->normalizeNamedParameter($key)] = $this->parseScalarValue($value);
                continue;
            }

            $params[] = $this->parseScalarValue($item);
        }

        return $params;
    }

    protected function hasNamedPlaceholders(string $sql): bool
    {
        return 1 === preg_match('/:([A-Za-z_][A-Za-z0-9_]*)/', $sql);
    }

    protected function hasPositionalPlaceholders(string $sql): bool
    {
        return str_contains($sql, '?');
    }

    protected function normalizeNamedParameter(string $key): string
    {
        return str_starts_with($key, ':') ? $key : ':' . $key;
    }

    protected function parseScalarValue(string $value): mixed
    {
        $value = trim($value);

        if ('' === $value) {
            return '';
        }

        return match (strtolower($value)) {
            'true' => true,
            'false' => false,
            'null' => null,
            default => $this->parseStructuredScalarValue($value),
        };
    }

    protected function parseStructuredScalarValue(string $value): mixed
    {
        if (1 === preg_match('/^-?\d+$/', $value)) {
            return (int) $value;
        }

        if (is_numeric($value)) {
            return (float) $value;
        }

        if (str_starts_with($value, '{') || str_starts_with($value, '[')) {
            try {
                return json_decode($value, true, flags: JSON_THROW_ON_ERROR | JSON_INVALID_UTF8_IGNORE);
            } catch (Throwable) {
                return $value;
            }
        }

        return $value;
    }
}
