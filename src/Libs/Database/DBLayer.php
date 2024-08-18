<?php
/** @noinspection PhpUnhandledExceptionInspection */

declare(strict_types=1);

namespace App\Libs\Database;

use App\Libs\Exceptions\DatabaseException as DBException;
use Closure;
use PDO;
use PDOException;
use PDOStatement;
use RuntimeException;

final class DBLayer
{
    private const int LOCK_RETRY = 4;

    private int $count = 0;

    private string $driver;

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

    public function __construct(private PDO $pdo)
    {
        $driver = $this->pdo->getAttribute(PDO::ATTR_DRIVER_NAME);

        if (is_string($driver)) {
            $this->driver = $driver;
        }
    }

    public function exec(string $sql, array $options = []): int|false
    {
        try {
            $queryString = $sql;

            $this->last = [
                'sql' => $queryString,
                'bind' => [],
            ];

            $stmt = $this->pdo->exec($queryString);
        } catch (PDOException $e) {
            throw (new DBException($e->getMessage()))
                ->setInfo($queryString, [], $e->errorInfo ?? [], $e->getCode())
                ->setFile($e->getTrace()[$options['tracer'] ?? 1]['file'] ?? $e->getFile())
                ->setLine($e->getTrace()[$options['tracer'] ?? 1]['line'] ?? $e->getLine())
                ->setOptions([]);
        }

        return $stmt;
    }

    public function query(string $queryString, array $bind = [], array $options = []): PDOStatement
    {
        try {
            $this->last = [
                'sql' => $queryString,
                'bind' => $bind,
            ];

            $stmt = $this->pdo->prepare($queryString);

            if (!($stmt instanceof PDOStatement)) {
                throw new PDOException('Unable to prepare statement.');
            }

            $stmt->execute($bind);

            if (false !== stripos($queryString, 'SQL_CALC_FOUND_ROWS')) {
                if (false !== ($countStatement = $this->pdo->query('SELECT FOUND_ROWS();'))) {
                    $this->count = (int)$countStatement->fetch(PDO::FETCH_COLUMN);
                }
            }
        } catch (PDOException $e) {
            throw (new DBException($e->getMessage()))
                ->setInfo($queryString, $bind, $e->errorInfo ?? [], $e->getCode())
                ->setFile($e->getTrace()[$options['tracer'] ?? 1]['file'] ?? $e->getFile())
                ->setLine($e->getTrace()[$options['tracer'] ?? 1]['line'] ?? $e->getLine())
                ->setOptions($options);
        }

        return $stmt;
    }

    public function start(): bool
    {
        if ($this->pdo->inTransaction()) {
            return false;
        }

        return $this->pdo->beginTransaction();
    }

    public function commit(): bool
    {
        return $this->pdo->commit();
    }

    public function rollBack(): bool
    {
        return $this->pdo->rollBack();
    }

    public function inTransaction(): bool
    {
        return $this->pdo->inTransaction();
    }

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

        if (array_key_exists('limit', $options)) {
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
                $cols
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
            $_ = $this->limitExpr((int)$options['limit'], $options['start'] ?? null);

            $query[] = $_['query'];
            $bind = array_replace_recursive($bind, $_['bind']);
        }

        $query = array_map('trim', $query);

        if (!array_key_exists('tracer', $options)) {
            $options['tracer'] = 2;
        }

        return $this->query(implode(' ', $query), $bind, $options);
    }

    public function getCount(string $table, array $conditions = [], array $options = []): void
    {
        $bind = $query = [];

        $query[] = "SELECT COUNT(*) FROM " . $this->escapeIdentifier($table, true);

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

        $this->count = (int)$this->query(implode(' ', $query), $bind, $options)->fetchColumn();
    }

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
                $this->escapeIdentifier($bindKey)
            );
        }

        $query[] = implode(', ', $updated);

        $cond = $this->conditionParser($conditions);
        $bind = array_replace_recursive($bind, $cond['bind']);

        $query[] = 'WHERE ' . implode(' AND ', $cond['query']);

        if (array_key_exists('limit', $options)) {
            $_ = $this->limitExpr((int)$options['limit']);

            $query[] = $_['query'];
            $bind = array_replace_recursive($bind, $_['bind']);
        }

        $query = array_map('trim', $query);

        if (!array_key_exists('tracer', $options)) {
            $options['tracer'] = 1;
        }

        return $this->query(implode(' ', $query), $bind, $options);
    }

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
            $queryString
        );

        $queryString = trim($queryString);

        if (!array_key_exists('tracer', $options)) {
            $options['tracer'] = 1;
        }

        return $this->query($queryString, $conditions, $options);
    }

    public function quote(mixed $text, int $type = PDO::PARAM_STR): string
    {
        return (string)$this->pdo->quote($text, $type);
    }

    public function escape(string $text): string
    {
        return mb_substr($this->quote($text), 1, -1, 'UTF-8');
    }

    public function id(string|null $name = null): string
    {
        return false !== ($id = $this->pdo->lastInsertId($name)) ? $id : '';
    }

    public function totalRows(): int
    {
        return $this->count;
    }

    public function close(): bool
    {
        return true;
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
                    $text
                )
            );
        }

        // The first character cannot be [0-9]:
        if (preg_match('/^\d/', $text)) {
            throw new RuntimeException(
                sprintf(
                    'Invalid identifier "%s": Must begin with a letter or underscore.',
                    $text
                )
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

    public function getDriver(): string
    {
        return $this->driver;
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
                            (function ($expr): string {
                                return match ($expr) {
                                    self::IS_EQUAL => self::IS_EQUAL,
                                    self::IS_NOT_EQUAL => self::IS_NOT_EQUAL,
                                    self::IS_HIGHER_THAN => self::IS_HIGHER_THAN,
                                    self::IS_HIGHER_THAN_OR_EQUAL => self::IS_HIGHER_THAN_OR_EQUAL,
                                    self::IS_LOWER_THAN => self::IS_LOWER_THAN,
                                    self::IS_LOWER_THAN_OR_EQUAL => self::IS_LOWER_THAN_OR_EQUAL,
                                    default => throw new RuntimeException(sprintf('SQL (%s) not implemented.', $expr)),
                                };
                            })(
                                $opt[0]
                            )
                        ],
                        '(column) (expr) :(bind)'
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
                            (function ($expr): string {
                                return match ($expr) {
                                    self::IS_BETWEEN => self::IS_BETWEEN,
                                    self::IS_NOT_BETWEEN => self::IS_NOT_BETWEEN,
                                    default => throw new RuntimeException(sprintf('SQL (%s) not implemented.', $expr)),
                                };
                            })(
                                $opt[0]
                            )
                        ],
                        "(column) (expr) (bind1) AND (bind2)"
                    );
                    $bind[$eBindName1] = $opt[1][0];
                    $bind[$eBindName2] = $opt[1][1];
                    break;
                case self::IS_NULL:
                case self::IS_NOT_NULL:
                    $keys[] = str_replace(
                        ['(column)'],
                        [$eColumnName],
                        (function ($expr): string {
                            return (self::IS_NULL === $expr) ? "(column) IS NULL" : "(column) IS NOT NULL";
                        })(
                            $opt[0]
                        )
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
                            (function ($expr): string {
                                return match ($expr) {
                                    self::IS_LIKE => self::IS_LIKE,
                                    self::IS_NOT_LIKE => self::IS_NOT_LIKE,
                                    default => throw new RuntimeException(sprintf('SQL (%s) not implemented.', $expr)),
                                };
                            })(
                                $opt[0]
                            )
                        ],
                        (function ($driver) {
                            if ('sqlite' === $driver) {
                                return "(column) (expr) '%' || :(bind) || '%'";
                            }
                            return "(column) (expr) CONCAT('%',:(bind),'%')";
                        })(
                            $this->driver
                        )
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
                            (function ($expr): string {
                                return match ($expr) {
                                    self::IS_IN => self::IS_IN,
                                    self::IS_NOT_IN => self::IS_NOT_IN,
                                    default => throw new RuntimeException(sprintf('SQL (%s) not implemented.', $expr)),
                                };
                            })(
                                $opt[0]
                            )
                        ],
                        "(column) (expr) ((bind))"
                    );
                    $bind = array_replace_recursive($bind, $inExpr['bind'] ?? []);
                    break;
                case self::IS_MATCH_AGAINST:
                    if (!isset($opt[1], $opt[2])) {
                        throw new RuntimeException('IS_MATCH_AGAINST: expects 2 parameters.');
                    }

                    if (!is_array($opt[1])) {
                        throw new RuntimeException(
                            sprintf('IS_MATCH_AGAINST: expects parameter 1 to be array. %s given.', gettype($opt[1]))
                        );
                    }

                    if (!is_string($opt[2])) {
                        throw new RuntimeException(
                            sprintf('IS_MATCH_AGAINST: expects parameter 2 to be string. %s given', gettype($opt[2]))
                        );
                    }

                    $eBindName = '__db_ftS_' . random_int(1, 1000);
                    $keys[] = sprintf(
                        "MATCH(%s) AGAINST(%s)",
                        implode(', ', array_map(fn($columns) => $this->escapeIdentifier($columns, true), $opt[1])),
                        ':' . $eBindName
                    );

                    $bind[$eBindName] = $opt[2];
                    break;
                case self::IS_JSON_CONTAINS:
                    if (!isset($opt[1], $opt[2])) {
                        throw new RuntimeException('IS_JSON_CONTAINS: expects 2 parameters.');
                    }

                    $eBindName = '__db_jc_' . random_int(1, 1000);

                    $keys[] = sprintf(
                        "JSON_CONTAINS(%s, %s) > %d",
                        $this->escapeIdentifier($opt[1], true),
                        ':' . $eBindName,
                        (int)($opt[3] ?? 0)
                    );

                    $bind[$eBindName] = $opt[2];
                    break;
                case self::IS_JSON_EXTRACT:
                    if (!isset($opt[1], $opt[2], $opt[3])) {
                        throw new RuntimeException('IS_JSON_CONTAINS: expects 3 parameters.');
                    }

                    $eBindName = '__db_je_' . random_int(1, 1000);

                    $keys[] = sprintf(
                        "JSON_EXTRACT(%s, %s) %s %s",
                        $this->escapeIdentifier($column, true),
                        $opt[1],
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
            'query' => ':' . implode(', :', array_keys($bind))
        ];
    }

    private function groupByExpr(array $groupBy): array
    {
        $groupBy = array_map(
            fn($val) => $this->escapeIdentifier($val, true),
            $groupBy
        );

        return ['query' => 'GROUP BY ' . implode(', ', $groupBy)];
    }

    private function orderByExpr(array $orderBy): array
    {
        $sortBy = [];

        foreach ($orderBy as $columnName => $columnSort) {
            $columnSort = ('DESC' === strtoupper($columnSort)) ? 'DESC' : 'ASC';

            $sortBy[] = $this->escapeIdentifier($columnName, true) . ' ' . $columnSort;
        }

        return ['query' => 'ORDER BY ' . implode(', ', $sortBy)];
    }

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

    public function getLastStatement(): array
    {
        return $this->last;
    }

    public function transactional(Closure $callback): mixed
    {
        $autoStartTransaction = false === $this->inTransaction();

        for ($i = 1; $i <= self::LOCK_RETRY; $i++) {
            try {
                if (!$autoStartTransaction) {
                    $this->start();
                }

                $result = $callback($this);

                if (!$autoStartTransaction) {
                    $this->commit();
                }

                $this->last = $this->getLastStatement();

                return $result;
            } catch (DBException $e) {
                if (!$autoStartTransaction && $this->inTransaction()) {
                    $this->rollBack();
                }

                //-- sometimes sqlite is locked, therefore attempt to sleep until it's unlocked.
                if (false !== stripos($e->getMessage(), 'database is locked')) {
                    // throw exception if happens self::LOCK_RETRY times in a row.
                    if ($i >= self::LOCK_RETRY) {
                        throw $e;
                    }
                    /** @noinspection PhpUnhandledExceptionInspection */
                    sleep(self::LOCK_RETRY + random_int(1, 3));
                } else {
                    throw $e;
                }
            }
        }

        /**
         * We return in try or throw exception.
         * As such this return should never be reached.
         */
        return null;
    }
}
