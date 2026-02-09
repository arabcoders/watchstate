<?php
/** @noinspection PhpUnhandledExceptionInspection */

declare(strict_types=1);

namespace App\Libs\Database;

use App\Libs\Exceptions\DBLayerException;
use App\Libs\Exceptions\RuntimeException;
use Closure;
use PDO;
use PDOException;
use PDOStatement;
use Psr\Log\LoggerAwareInterface;
use Psr\Log\LoggerAwareTrait;

final class DBLayer implements LoggerAwareInterface
{
    use LoggerAwareTrait;

    private const int LOCK_RETRY = 4;

    private int $retry;

    private int $count = 0;

    private string $driver = '';

    private array $last = [
        'sql' => '',
        'bind' => [],
    ];

    public const string WRITE_MODE = 'inWriteMode';

    public const string IS_EQUAL = '=';
    public const string IS_LIKE = 'LIKE';
    public const string IS_IN = 'IN';
    public const string IS_NULL = 'IS NULL';
    public const string IS_HIGHER_THAN = '>';
    public const string IS_HIGHER_THAN_OR_EQUAL = '>=';
    public const string IS_LOWER_THAN = '<';
    public const string IS_LOWER_THAN_OR_EQUAL = '<=';
    public const string IS_NOT_EQUAL = '!=';
    public const string IS_NOT_LIKE = 'NOT LIKE';
    public const string IS_NOT_NULL = 'IS NOT NULL';
    public const string IS_NOT_IN = 'NOT IN';
    public const string IS_BETWEEN = 'BETWEEN';
    public const string IS_NOT_BETWEEN = 'NOT BETWEEN';
    public const string IS_LEFT_JOIN = 'LEFT JOIN';
    public const string IS_INNER_JOIN = 'INNER JOIN';
    public const string IS_LEFT_OUTER_JOIN = 'LEFT OUTER JOIN';
    public const string IS_MATCH_AGAINST = 'MATCH() AGAINST()';
    public const string IS_JSON_CONTAINS = 'JSON_CONTAINS';
    public const string IS_JSON_EXTRACT = 'JSON_EXTRACT';
    public const string IS_JSON_SEARCH = 'JSON_SEARCH';

    public function __construct(
        private readonly PDO $pdo,
        private array $options = [],
    ) {
        $driver = $this->pdo->getAttribute(PDO::ATTR_DRIVER_NAME);

        if (is_string($driver)) {
            $this->driver = $driver;
        }

        $this->retry = ag($this->options, 'retry', self::LOCK_RETRY);
    }

    /**
     * Create a new instance with the given PDO object and options.
     *
     * @param PDO $pdo The PDO object.
     * @param array|null $options The options to be passed to the new instance, or null to use the current options.
     *
     * @return self The new instance.
     */
    public function withPDO(PDO $pdo, ?array $options = null): self
    {
        return new self($pdo, $options ?? $this->options);
    }

    /**
     * Execute a SQL statement and return the number of affected rows.
     * The execution will be wrapped into {@link DBLayer::wrap()} method. to handle database locks.
     *
     * @param string $sql The SQL statement to execute.
     * @param array $options An optional array of options to be passed to the callback function.
     *
     * @return int|false The number of affected rows, or false on failure.
     */
    public function exec(string $sql, array $options = []): int|false
    {
        $opts = [];

        if (true === ag_exists($options, 'on_failure')) {
            $opts['on_failure'] = $options['on_failure'];
        }

        return $this->wrap(function (DBLayer $db) use ($sql) {
            $queryString = $sql;

            $this->last = [
                'sql' => $queryString,
                'bind' => [],
            ];

            return $db->pdo->exec($queryString);
        }, $opts);
    }

    /**
     * Execute an SQL statement and return the PDOStatement object.
     *
     * @param PDOStatement|string $sql The SQL statement to execute.
     * @param array $bind The bind parameters for the SQL statement.
     * @param array $options An optional array of options to be passed to the callback function.
     *
     * @return PDOStatement The returned results wrapped in a PDOStatement object.
     */
    public function query(PDOStatement|string $sql, array $bind = [], array $options = []): PDOStatement
    {
        $opts = [];
        if (true === ag_exists($options, 'on_failure')) {
            $opts['on_failure'] = $options['on_failure'];
        }

        return $this->wrap(function (DBLayer $db) use ($sql, $bind) {
            $isStatement = $sql instanceof PDOStatement;
            $queryString = $isStatement ? $sql->queryString : $sql;

            $this->last = [
                'sql' => $queryString,
                'bind' => $bind,
            ];

            $stmt = $isStatement ? $sql : $db->prepare($sql);

            if (!empty($bind)) {
                array_map(
                    static fn($k, $v) => $stmt->bindValue($k, $v, is_int($v) ? PDO::PARAM_INT : PDO::PARAM_STR),
                    array_keys($bind),
                    $bind,
                );
            }

            $stmt->execute();

            return $stmt;
        }, $opts);
    }

    /**
     * Start a transaction.
     *
     * @return bool Returns true on success, false on failure.
     */
    public function start(): bool
    {
        if (true === $this->pdo->inTransaction()) {
            return false;
        }

        return $this->pdo->beginTransaction();
    }

    /**
     * Commit a transaction.
     *
     * @return bool Returns true on success, false on failure.
     */
    public function commit(): bool
    {
        return $this->pdo->commit();
    }

    /**
     * Rollback a transaction.
     *
     * @return bool Returns true on success, false on failure.
     */
    public function rollBack(): bool
    {
        return $this->pdo->rollBack();
    }

    /**
     * Checks if inside a transaction.
     *
     * @return bool Returns true if a transaction is currently active, false otherwise.
     */
    public function inTransaction(): bool
    {
        return $this->pdo->inTransaction();
    }

    /**
     * This method wraps db operations in a single transaction.
     *
     * @param Closure<DBLayer> $callback The callback function to be executed.
     * @param bool $auto (Optional) Whether to automatically start and commit the transaction.
     * @param array $options (Optional) An optional array of options to be passed the wrapper.
     *
     * @return mixed The result of the callback function.
     */
    public function transactional(Closure $callback, bool $auto = true, array $options = []): mixed
    {
        $autoStartTransaction = true === $auto && false === $this->inTransaction();

        if ($autoStartTransaction) {
            $options['on_failure'] = function ($e) {
                if ($this->inTransaction()) {
                    $this->rollBack();
                }
                throw $e;
            };
        }

        return $this->wrap(function (DBLayer $db, array $options = []) use ($callback, $autoStartTransaction) {
            if (true === $autoStartTransaction) {
                $db->start();
            }

            $result = $callback($this, $options);

            if (true === $autoStartTransaction) {
                $db->commit();
            }

            $this->last = $db->getLastStatement();
            return $result;
        }, $options);
    }

    /**
     * Prepare a statement for execution and return a PDOStatement object.
     *
     * @param string $sql The SQL statement to prepare.
     * @param array $options holds options for {@link PDOStatement} options.
     *
     * @return PDOStatement The returned results wrapped in a PDOStatement object.
     */
    public function prepare(string $sql, array $options = []): PDOStatement
    {
        $stmt = $this->pdo->prepare($sql, $options);

        if (false === $stmt instanceof PDOStatement) {
            throw new PDOException('Unable to prepare statement.');
        }

        return $stmt;
    }

    /**
     * Returns the ID of the last inserted row or sequence value.
     *
     * @return string|false return the last insert id or false on failure or not supported.
     */
    public function lastInsertId(): string|false
    {
        return $this->pdo->lastInsertId();
    }

    /**
     * Delete Statement.
     *
     * @param string $table The table name.
     * @param array $conditions The conditions to be met. i.e. WHERE clause.
     * @param array $options The options to be passed to the query method.
     *
     * @return PDOStatement The returned results wrapped in a PDOStatement object.
     */
    public function delete(string $table, array $conditions, array $options = []): PDOStatement
    {
        if (empty($conditions)) {
            throw new RuntimeException('Conditions Parameter is empty.');
        }

        $query = [];
        $cond = $this->conditionParser($conditions);
        $bind = $cond['bind'];

        $query[] = 'DELETE FROM ' . $this->escapeIdentifier($table, true) . ' WHERE';
        $query[] = implode(' AND ', $cond['query']);

        // -- For some reason, the SQLite authors don't include this feature in the amalgamation.
        // So it's effectively unavailable to most third-party drivers and programs that use the amalgamation
        // to compile SQLite. As we don't control the build version of SQLite, we can't guarantee that this
        // feature is available. So we'll just skip it for SQLite.
        $ignoreSafety = 'sqlite' !== $this->driver || true === (bool) ag($options, 'ignore_safety', false);
        if (array_key_exists('limit', $options) && $ignoreSafety) {
            $_ = $this->limitExpr($options['limit']);
            $query[] = $_['query'];
            $bind = array_replace_recursive($bind, $_['bind']);
        }

        $query = array_map('trim', $query);

        if (!array_key_exists('tracer', $options)) {
            $options['tracer'] = 2;
        }

        return $this->query(implode(' ', $query), $bind, $options);
    }

    /**
     * Select Statement.
     *
     * @param string $table
     * @param array<int,string> $cols
     * @param array<string,array|string|int|mixed> $conditions
     * @param array<string,mixed> $options
     *
     * @return PDOStatement
     */
    public function select(string $table, array $cols = [], array $conditions = [], array $options = []): PDOStatement
    {
        $bind = [];
        $col = '*';

        if (count($cols) >= 1) {
            $cols = array_map(
                function ($text) {
                    if ('*' === $text) {
                        return $text;
                    }
                    return $this->escapeIdentifier($text, true);
                },
                $cols,
            );

            $col = implode(', ', $cols);
        }

        if (array_key_exists('count', $options) && $options['count']) {
            $this->getCount($table, $conditions, $options);
        }

        $query = [];

        $query[] = "SELECT {$col} FROM " . $this->escapeIdentifier($table, true);

        if (!empty($conditions)) {
            $andOr = $options['andor'] ?? 'AND';
            $cond = $this->conditionParser($conditions);

            if (!empty($cond['query'])) {
                $query[] = 'WHERE ' . implode(" {$andOr} ", $cond['query']);
            }

            $bind = array_replace_recursive($bind, $cond['bind']);
        }

        if (array_key_exists('groupby', $options) && is_array($options['groupby'])) {
            $query[] = $this->groupByExpr($options['groupby'])['query'];
        }

        if (array_key_exists('orderby', $options) && is_array($options['orderby'])) {
            $query[] = $this->orderByExpr($options['orderby'])['query'];
        }

        if (array_key_exists('limit', $options)) {
            $_ = $this->limitExpr((int) $options['limit'], $options['start'] ?? null);

            $query[] = $_['query'];
            $bind = array_replace_recursive($bind, $_['bind']);
        }

        $query = array_map('trim', $query);

        if (!array_key_exists('tracer', $options)) {
            $options['tracer'] = 2;
        }

        return $this->query(implode(' ', $query), $bind, $options);
    }

    /**
     * Get the count of rows in a table.
     *
     * @param string $table The table name.
     * @param array $conditions The conditions to be met. i.e. WHERE clause.
     * @param array $options The options to be passed to the query method.
     *
     * @return int The number of rows based on the conditions.
     */
    public function getCount(string $table, array $conditions = [], array $options = []): int
    {
        $bind = $query = [];

        $query[] = 'SELECT COUNT(*) FROM ' . $this->escapeIdentifier($table, true);

        if (!empty($conditions)) {
            $cond = $this->conditionParser($conditions);

            if (!empty($cond['query'])) {
                $query[] = 'WHERE ' . implode(' AND ', $cond['query']);
            }

            $bind = $cond['bind'];
        }

        if (array_key_exists('groupby', $options) && is_array($options['groupby'])) {
            $query[] = $this->groupByExpr($options['groupby'])['query'];
        }

        if (array_key_exists('orderby', $options) && is_array($options['orderby'])) {
            $query[] = $this->orderByExpr($options['orderby'])['query'];
        }

        $query = array_map('trim', $query);

        if (!array_key_exists('tracer', $options)) {
            $options['tracer'] = 1;
        }

        $this->count = (int) $this->query(implode(' ', $query), $bind, $options)->fetchColumn();

        return $this->count;
    }

    /**
     * Update Statement.
     *
     * @param string $table The table name.
     * @param array $changes The changes to be made. i.e. SET clause.
     * @param array $conditions The conditions to be met. i.e. WHERE clause.
     * @param array $options The options to be passed to the query method.
     *
     * @return PDOStatement The returned results wrapped in a PDOStatement object.
     */
    public function update(string $table, array $changes, array $conditions, array $options = []): PDOStatement
    {
        if (empty($changes)) {
            throw new RuntimeException('Changes Parameter is empty.');
        }

        if (empty($conditions)) {
            throw new RuntimeException('Conditions Parameter is empty.');
        }

        $bind = $query = $updated = [];

        $query[] = 'UPDATE ' . $this->escapeIdentifier($table, true) . ' SET';

        foreach ($changes as $columnName => $columnValue) {
            $bindKey = '__dbu_' . $columnName;
            $bind[$bindKey] = $columnValue;
            $updated[] = sprintf(
                '%s = :%s',
                $this->escapeIdentifier($columnName, true),
                $this->escapeIdentifier($bindKey),
            );
        }

        $query[] = implode(', ', $updated);

        $cond = $this->conditionParser($conditions);
        $bind = array_replace_recursive($bind, $cond['bind']);

        $query[] = 'WHERE ' . implode(' AND ', $cond['query']);

        // -- For some reason, the SQLite authors don't include this feature in the amalgamation.
        // So it's effectively unavailable to most third-party drivers and programs that use the amalgamation
        // to compile SQLite. As we don't control the build version of SQLite, we can't guarantee that this
        // feature is available. So we'll just skip it for SQLite.
        $ignoreSafety = 'sqlite' !== $this->driver || true === (bool) ag($options, 'ignore_safety', false);
        if (array_key_exists('limit', $options) && $ignoreSafety) {
            $_ = $this->limitExpr((int) $options['limit']);

            $query[] = $_['query'];
            $bind = array_replace_recursive($bind, $_['bind']);
        }

        $query = array_map('trim', $query);

        if (!array_key_exists('tracer', $options)) {
            $options['tracer'] = 1;
        }

        return $this->query(implode(' ', $query), $bind, $options);
    }

    /**
     * Insert Statement.
     *
     * @param string $table The table name.
     * @param array $conditions Simple associative array of [column => value].
     * @param array $options The options to be passed to the query method.
     *
     * @return PDOStatement The returned results wrapped in a PDOStatement object.
     */
    public function insert(string $table, array $conditions, array $options = []): PDOStatement
    {
        if (empty($conditions)) {
            throw new RuntimeException('Conditions Parameter is empty, Expecting associative array.');
        }

        $queryString = 'INSERT INTO ' . $this->escapeIdentifier($table, true) . ' ((columns)) VALUES((values))';

        $columns = $placeholder = [];

        foreach (array_keys($conditions) as $v) {
            $columns[] = $this->escapeIdentifier($v, true);
            $placeholder[] = sprintf(':%s', $this->escapeIdentifier($v, false));
        }

        $queryString = str_replace(
            ['(columns)', '(values)'],
            [implode(', ', $columns), implode(', ', $placeholder)],
            $queryString,
        );

        $queryString = trim($queryString);

        if (!array_key_exists('tracer', $options)) {
            $options['tracer'] = 1;
        }

        return $this->query($queryString, $conditions, $options);
    }

    /**
     * Quote a string for use in a query.
     *
     * @param mixed $text The string to be quoted.
     * @param int $type Provides a data type hint for drivers that have alternate quoting styles.
     *
     * @return string The quoted string.
     */
    public function quote(mixed $text, int $type = PDO::PARAM_STR): string
    {
        return (string) $this->pdo->quote($text, $type);
    }

    /**
     * Get the ID generated in the last query.
     *
     * @param string|null $name The name of the sequence object from which the ID should be returned.
     * @return string The generated ID, or empty string if no ID was generated.
     */
    public function id(?string $name = null): string
    {
        return false !== ($id = $this->pdo->lastInsertId($name)) ? $id : '';
    }

    /**
     * Get the number of rows by using {@link DBLayer::getCount()}.
     *
     * @return int The total number of rows.
     */
    public function totalRows(): int
    {
        return $this->count;
    }

    /**
     * Make sure only valid characters make it in column/table names
     *
     * @see https://stackoverflow.com/questions/10573922/what-does-the-sql-standard-say-about-usage-of-backtick
     *
     * @param string $text table or column name
     * @param bool $quote certain SQLs escape column names (i.e. mysql with `backticks`)
     *
     * @return string
     */
    public function escapeIdentifier(string $text, bool $quote = false): string
    {
        // table or column has to be valid ASCII name.
        // this is opinionated, but we only allow [a-zA-Z0-9_] in column/table name.
        if (!preg_match('#\w#', $text)) {
            throw new RuntimeException(
                sprintf(
                    'Invalid identifier "%s": Column/table must be valid ASCII code.',
                    $text,
                ),
            );
        }

        // The first character cannot be [0-9]:
        if (preg_match('/^\d/', $text)) {
            throw new RuntimeException(
                sprintf(
                    'Invalid identifier "%s": Must begin with a letter or underscore.',
                    $text,
                ),
            );
        }

        if ($quote) {
            return match ($this->driver) {
                'mssql' => '[' . $text . ']',
                'mysql', 'mariadb' => '`' . $text . '`',
                default => '"' . $text . '"',
            };
        }

        return $text;
    }

    /**
     * Get the PDO driver name.
     *
     * @return string The driver name.
     */
    public function getDriver(): string
    {
        return $this->driver;
    }

    /**
     * Get reference to the PDO object.
     *
     * @return PDO The PDO object.
     */
    public function getBackend(): PDO
    {
        return $this->pdo;
    }

    private function conditionParser(array $conditions): array
    {
        $keys = $bind = [];

        foreach ($conditions as $column => $opt) {
            $column = trim($column);
            /** @noinspection PhpUnusedLocalVariableInspection */
            $eBindName = '__dbw_' . $this->escapeIdentifier($column);
            $eColumnName = $this->escapeIdentifier($column, true);

            if (!is_array($opt)) {
                $opt = [self::IS_EQUAL, $opt];
            }

            switch ($opt[0]) {
                case self::IS_EQUAL:
                case self::IS_NOT_EQUAL:
                case self::IS_HIGHER_THAN:
                case self::IS_HIGHER_THAN_OR_EQUAL:
                case self::IS_LOWER_THAN:
                case self::IS_LOWER_THAN_OR_EQUAL:
                    $eBindName = '__db_cOp_' . random_int(1, 10000);
                    $keys[] = str_replace(
                        ['(column)', '(bind)', '(expr)'],
                        [
                            $eColumnName,
                            $eBindName,
                            (static fn($expr): string => match ($expr) {
                                self::IS_EQUAL => self::IS_EQUAL,
                                self::IS_NOT_EQUAL => self::IS_NOT_EQUAL,
                                self::IS_HIGHER_THAN => self::IS_HIGHER_THAN,
                                self::IS_HIGHER_THAN_OR_EQUAL => self::IS_HIGHER_THAN_OR_EQUAL,
                                self::IS_LOWER_THAN => self::IS_LOWER_THAN,
                                self::IS_LOWER_THAN_OR_EQUAL => self::IS_LOWER_THAN_OR_EQUAL,
                                default => throw new RuntimeException(sprintf('SQL (%s) not implemented.', $expr)),
                            })(
                                $opt[0],
                            ),
                        ],
                        '(column) (expr) :(bind)',
                    );
                    $bind[$eBindName] = $opt[1];
                    break;
                case self::IS_BETWEEN:
                case self::IS_NOT_BETWEEN:
                    $eBindName1 = ':__db_b1_' . random_int(1, 1000);
                    $eBindName2 = ':__db_b2_' . random_int(1, 1000);
                    $keys[] = str_replace(
                        ['(column)', '(bind1)', '(bind2)', '(expr)'],
                        [
                            $eColumnName,
                            $eBindName1,
                            $eBindName2,
                            (static fn($expr): string => match ($expr) {
                                self::IS_BETWEEN => self::IS_BETWEEN,
                                self::IS_NOT_BETWEEN => self::IS_NOT_BETWEEN,
                                default => throw new RuntimeException(sprintf('SQL (%s) not implemented.', $expr)),
                            })(
                                $opt[0],
                            ),
                        ],
                        '(column) (expr) (bind1) AND (bind2)',
                    );
                    $bind[$eBindName1] = $opt[1][0];
                    $bind[$eBindName2] = $opt[1][1];
                    break;
                case self::IS_NULL:
                case self::IS_NOT_NULL:
                    $keys[] = str_replace(
                        ['(column)'],
                        [$eColumnName],
                        (static fn($expr): string => self::IS_NULL === $expr ? '(column) IS NULL' : '(column) IS NOT NULL')(
                            $opt[0],
                        ),
                    );
                    break;
                case self::IS_LIKE:
                case self::IS_NOT_LIKE:
                    $eBindName = '__db_lk_' . random_int(1, 1000);
                    $keys[] = str_replace(
                        ['(column)', '(bind)', '(expr)'],
                        [
                            $eColumnName,
                            $eBindName,
                            (static fn($expr): string => match ($expr) {
                                self::IS_LIKE => self::IS_LIKE,
                                self::IS_NOT_LIKE => self::IS_NOT_LIKE,
                                default => throw new RuntimeException(sprintf('SQL (%s) not implemented.', $expr)),
                            })(
                                $opt[0],
                            ),
                        ],
                        (static function ($driver) {
                            if ('sqlite' === $driver) {
                                return "(column) (expr) '%' || :(bind) || '%'";
                            }
                            return "(column) (expr) CONCAT('%',:(bind),'%')";
                        })(
                            $this->driver,
                        ),
                    );
                    $bind[$eBindName] = $opt[1];
                    break;
                case self::IS_IN:
                case self::IS_NOT_IN:
                    $inExpr = $this->inExpr($column, $opt[1]);
                    $keys[] = str_replace(
                        ['(column)', '(bind)', '(expr)'],
                        [
                            $eColumnName,
                            $inExpr['query'],
                            (static fn($expr): string => match ($expr) {
                                self::IS_IN => self::IS_IN,
                                self::IS_NOT_IN => self::IS_NOT_IN,
                                default => throw new RuntimeException(sprintf('SQL (%s) not implemented.', $expr)),
                            })(
                                $opt[0],
                            ),
                        ],
                        '(column) (expr) ((bind))',
                    );
                    $bind = array_replace_recursive($bind, $inExpr['bind'] ?? []);
                    break;
                case self::IS_MATCH_AGAINST:
                    if (!isset($opt[1], $opt[2])) {
                        throw new RuntimeException('IS_MATCH_AGAINST: expects 2 parameters.');
                    }

                    if (!is_array($opt[1])) {
                        throw new RuntimeException(
                            sprintf('IS_MATCH_AGAINST: expects parameter 1 to be array. %s given.', gettype($opt[1])),
                        );
                    }

                    if (!is_string($opt[2])) {
                        throw new RuntimeException(
                            sprintf('IS_MATCH_AGAINST: expects parameter 2 to be string. %s given', gettype($opt[2])),
                        );
                    }

                    $eBindName = '__db_ftS_' . random_int(1, 1000);

                    $keys[] = str_replace(
                        ['(column)', '(bind)', '(expr)'],
                        [
                            $eColumnName,
                            $eBindName,
                            implode(', ', array_map(fn($columns) => $this->escapeIdentifier($columns, true), $opt[1])),
                        ],
                        (static function ($driver) {
                            if ('sqlite' === $driver) {
                                return '(column) MATCH :(bind)';
                            }
                            return 'MATCH((expr)) AGAINST(:(bind))';
                        })(
                            $this->driver,
                        ),
                    );

                    $bind[$eBindName] = $opt[2];
                    break;
                case self::IS_JSON_CONTAINS:
                    if (!isset($opt[1], $opt[2])) {
                        throw new RuntimeException('IS_JSON_CONTAINS: expects 2 parameters.');
                    }

                    $eBindName = '__db_jc_' . random_int(1, 1000);

                    $keys[] = sprintf(
                        'JSON_CONTAINS(%s, %s) > %d',
                        $this->escapeIdentifier($opt[1], true),
                        ':' . $eBindName,
                        (int) ($opt[3] ?? 0),
                    );

                    $bind[$eBindName] = $opt[2];
                    break;
                case self::IS_JSON_EXTRACT:
                    if (!isset($opt[1], $opt[2], $opt[3])) {
                        throw new RuntimeException('IS_JSON_EXTRACT: expects 3 parameters.');
                    }

                    $eBindName = '__db_je_' . random_int(1, 1000);

                    $keys[] = sprintf(
                        'JSON_EXTRACT(%s, %s) %s %s',
                        $this->escapeIdentifier($column, true),
                        $this->escapeIdentifier($opt[1], true),
                        $opt[2],
                        ':' . $eBindName,
                    );

                    $bind[$eBindName] = $opt[3];
                    break;
                case self::IS_INNER_JOIN:
                case self::IS_LEFT_JOIN:
                case self::IS_LEFT_OUTER_JOIN:
                default:
                    throw new RuntimeException(sprintf('SQL (%s) expr not implemented.', $opt[0]));
            }
        }

        return [
            'bind' => $bind,
            'query' => $keys,
        ];
    }

    /**
     * Create an IN expression.
     *
     * @param string $key column name
     * @param array $parameters array of values.
     *
     * @return array{bind: array<string, mixed>, query: string}, The bind and query.
     */
    private function inExpr(string $key, array $parameters): array
    {
        $i = 0;
        $token = "__in_{$key}_";
        $bind = [];

        foreach ($parameters as $param) {
            $i++;
            $bind[$token . $i] = $param;
        }

        return [
            'bind' => $bind,
            'query' => ':' . implode(', :', array_keys($bind)),
        ];
    }

    /**
     * Create a GROUP BY expression.
     *
     * @param array $groupBy The columns to group by.
     *
     * @return array{query: string} The query.
     */
    private function groupByExpr(array $groupBy): array
    {
        $groupBy = array_map(
            fn($val) => $this->escapeIdentifier($val, true),
            $groupBy,
        );

        return ['query' => 'GROUP BY ' . implode(', ', $groupBy)];
    }

    /**
     * Create an ORDER BY expression.
     *
     * @param array $orderBy The columns to order by.
     *
     * @return array{query: string} The query.
     */
    private function orderByExpr(array $orderBy): array
    {
        $sortBy = [];

        foreach ($orderBy as $columnName => $columnSort) {
            $columnSort = 'DESC' === strtoupper($columnSort) ? 'DESC' : 'ASC';

            $sortBy[] = $this->escapeIdentifier($columnName, true) . ' ' . $columnSort;
        }

        return ['query' => 'ORDER BY ' . implode(', ', $sortBy)];
    }

    /**
     * Create a LIMIT expression.
     *
     * @param int $limit The limit.
     * @param int|null $start The start.
     *
     * @return array{bind: array<string, int>, query: string} The bind and query.
     */
    private function limitExpr(int $limit, ?int $start = null): array
    {
        $bind = [
            '__db_limit' => $limit,
        ];

        if (is_int($start)) {
            $query = 'LIMIT :__db_start, :__db_limit';

            $bind['__db_start'] = $start;
        } else {
            $query = 'LIMIT :__db_limit';
        }

        return [
            'bind' => $bind,
            'query' => $query,
        ];
    }

    /**
     * Get the last executed statement.
     *
     * @return array The last executed statement.
     */
    public function getLastStatement(): array
    {
        return $this->last;
    }

    /**
     * Wraps the given callback function with a retry mechanism to handle database locks.
     *
     * @param Closure{DBLayer,array} $callback The callback function to be executed.
     * @param array $options An optional array of options to be passed to the callback function.
     *
     * @return mixed The result of the callback function.
     *
     * @throws DBLayerException If an error occurs while executing the callback function.
     */
    private function wrap(Closure $callback, array $options = []): mixed
    {
        static $lastFailure = [];
        $on_lock = ag($options, 'on_lock', null);
        $errorHandler = ag($options, 'on_failure', null);
        $exception = null;
        if (false === ag_exists($options, 'attempt')) {
            $options['attempt'] = 0;
        }

        try {
            return $callback($this, $options);
        } catch (PDOException $e) {
            $attempts = (int) ag($options, 'attempts', 0);
            if (true === str_contains(strtolower($e->getMessage()), 'database is locked')) {
                if ($attempts >= $this->retry) {
                    throw new DBLayerException($e->getMessage(), (int) $e->getCode(), $e)
                        ->setFile($e->getFile())
                        ->setLine($e->getLine());
                }

                $sleep = (int) ag($options, 'max_sleep', rand(1, 4));

                $this->logger?->warning("PDOAdapter: Database is locked. sleeping for '{sleep}s'.", [
                    'sleep' => $sleep,
                ]);

                $options['attempts'] = $attempts + 1;
                if (null !== $on_lock) {
                    return $on_lock($e, $callback, $options);
                }

                sleep($sleep);

                return $this->wrap($callback, $options);
            } else {
                $exception = $e;
                if (null !== $errorHandler && ag($lastFailure, 'message') !== $e->getMessage()) {
                    $lastFailure = [
                        'code' => $e->getCode(),
                        'message' => $e->getMessage(),
                        'time' => time(),
                    ];
                    return $errorHandler($e, $callback, $options);
                }

                if ($e instanceof DBLayerException) {
                    throw $e;
                }

                throw new DBLayerException($e->getMessage(), (int) $e->getCode(), $e)
                    ->setInfo($this->last['sql'], $this->last['bind'], $e->errorInfo ?? [], $e->getCode())
                    ->setFile($e->getFile())
                    ->setLine($e->getLine());
            }
        } finally {
            if (null === $exception) {
                $lastFailure = [];
            }
        }
    }
}
