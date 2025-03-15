<?php /** @noinspection SqlNoDataSourceInspection */

namespace splitbrain\phpsqlite;

/**
 * Helpers to access a SQLite Database with automatic schema migration
 */
class SQLite
{
    /** @var \PDO */
    protected $pdo;

    /** @var string */
    protected $schemadir;

    /**
     * Constructor
     *
     * If the database is a string, it is assumed to be a filename and a new PDO object is created. This is
     * the preferred way to use this class. It will automatically enable foreign keys and set the error mode to
     * exceptions.
     *
     * If you pass an already initialized PDO object, it will be used as is. But this library might not work
     * as expected if errors are not thrown as exceptions.
     *
     * @param string|\PDO $database filename or already initialized PDO object
     * @param string $schemadir directory with schema migration files
     */
    public function __construct($database, $schemadir)
    {
        $this->schemadir = $schemadir;
        if (is_a($database, \PDO::class)) {
            $this->pdo = $database;
        } else {
            $this->pdo = new \PDO(
                'sqlite:' . $database,
                null,
                null,
                [
                    \PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION,
                    \PDO::ATTR_TIMEOUT => 10, // wait for locks up to 10 seconds
                ]
            );
            $this->pdo->exec('PRAGMA foreign_keys = ON;');

            try {
                // See https://www.sqlite.org/wal.html
                $this->exec('PRAGMA journal_mode=WAL');
            } catch (\Exception $e) {
                // ignore if WAL is not supported
            }
        }
    }

    // region public API

    /**
     * Migrate the database to the latest version
     *
     * Either calls this always after initialization or from a command line tool.
     */
    public function migrate()
    {
        // apply migrations if needed
        $currentVersion = $this->currentDbVersion();
        $migrations = $this->getMigrationsToApply($currentVersion);
        if ($migrations) {
            foreach ($migrations as $version => $database) {
                $this->applyMigration($database, $version);
            }
            $this->pdo->exec('VACUUM');
        }
    }

    /**
     * Direct access to the PDO object
     * @return \PDO
     */
    public function pdo()
    {
        return $this->pdo;
    }

    /**
     * Execute a statement and return it
     *
     * @param string $sql
     * @param ...mixed|array $parameters
     * @return \PDOStatement Be sure to close the cursor yourself
     * @throws \PDOException
     */
    public function query(string $sql, ...$parameters): \PDOStatement
    {
        if ($parameters && is_array($parameters[0])) $parameters = $parameters[0];

        // Statement preparation sometime throws ValueErrors instead of PDOExceptions, we streamline here
        try {
            $stmt = $this->pdo->prepare($sql);
        } catch (\Throwable $e) {
            throw new \PDOException($e->getMessage(), (int)$e->getCode(), $e);
        }

        $stmt->execute($parameters);

        return $stmt;
    }


    /**
     * Execute a statement and return metadata
     *
     * Returns the last insert ID on INSERTs or the number of affected rows
     *
     * @param string $sql
     * @param ...mixed|array $parameters
     * @return int
     * @throws \PDOException
     */
    public function exec(string $sql, ...$parameters): int
    {
        $stmt = $this->query($sql, ...$parameters);

        $count = $stmt->rowCount();
        $stmt->closeCursor();
        if ($count && preg_match('/^INSERT /i', $sql)) {
            return (int) $this->queryValue('SELECT last_insert_rowid()');
        }

        return $count;
    }

    /**
     * Simple query abstraction
     *
     * Returns all data as an array of associative arrays
     *
     * @param string $sql
     * @param ...mixed|array $params
     * @return array
     * @throws \PDOException
     */
    public function queryAll(string $sql, ...$params): array
    {
        $stmt = $this->query($sql, ...$params);
        $data = $stmt->fetchAll(\PDO::FETCH_ASSOC);
        $stmt->closeCursor();
        return $data;
    }

    /**
     * Query one single row
     *
     * @param string $sql
     * @param ...mixed|array $params
     * @return array|null
     * @throws \PDOException
     */
    public function queryRecord(string $sql, ...$params): ?array
    {
        $stmt = $this->query($sql, ...$params);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);
        $stmt->closeCursor();
        if (is_array($row) && count($row)) {
            return $row;
        }
        return null;
    }

    /**
     * Insert or replace the given data into the table
     *
     * @param string $table
     * @param array $data
     * @param bool $replace Conflict resolution, replace or ignore
     * @return array|null Either the inserted row or null if nothing was inserted
     * @throws \PDOException
     */
    public function saveRecord(string $table, array $data, bool $replace = true): ?array
    {
        $columns = array_map(static fn($column) => '"' . $column . '"', array_keys($data));
        $values = array_values($data);
        $placeholders = array_pad([], count($columns), '?');

        if ($replace) {
            $command = 'REPLACE';
        } else {
            $command = 'INSERT OR IGNORE';
        }

        /** @noinspection SqlResolve */
        $sql = $command . ' INTO "' . $table . '" (' . implode(',', $columns) . ') VALUES (' . implode(
                ',',
                $placeholders
            ) . ')';
        $stm = $this->query($sql, $values);
        $success = $stm->rowCount();
        $stm->closeCursor();

        if ($success) {
            $sql = 'SELECT * FROM "' . $table . '" WHERE rowid = last_insert_rowid()';
            return $this->queryRecord($sql);
        }
        return null;
    }

    /**
     * Execute a query that returns a single value
     *
     * @param string $sql
     * @param ...mixed|array $params
     * @return mixed|null
     * @throws \PDOException
     */
    public function queryValue(string $sql, ...$params)
    {
        $result = $this->queryAll($sql, ...$params);
        if (count($result)) {
            return array_values($result[0])[0];
        }
        return null;
    }

    /**
     * Execute a query that returns a list of key-value pairs
     *
     * The first column is used as key, the second as value. Any additional columns are ignored.
     *
     * @param string $sql
     * @param ...mixed|array $params
     * @return array
     */
    public function queryKeyValueList($sql, ...$params): array
    {
        $result = $this->queryAll($sql, ...$params);
        if (!$result) return [];
        if (count(array_keys($result[0])) != 2) {
            throw new \RuntimeException('queryKeyValueList expects a query that returns exactly two columns');
        }
        [$key, $val] = array_keys($result[0]);

        return array_combine(
            array_column($result, $key),
            array_column($result, $val)
        );
    }

    /**
     * Get a config value from the opt table
     *
     * @param string $conf Config name
     * @param mixed $default What to return if the value isn't set
     * @return mixed
     */
    public function getOpt(string $conf, $default = null)
    {
        $value = $this->queryValue("SELECT val FROM opt WHERE conf = ?", [$conf]);
        if ($value === null) return $default;
        return $value;
    }

    /**
     * Set a config value in the opt table
     *
     * @param string $conf
     * @param mixed $value
     * @return void
     */
    public function setOpt(string $conf, $value)
    {
        $this->exec('REPLACE INTO opt (conf,val) VALUES (?,?)', [$conf, $value]);
    }

    // endregion

    // region migration handling

    /**
     * Read the current version from the opt table
     *
     * The opt table is created here if not found
     *
     * @return int
     */
    protected function currentDbVersion(): int
    {
        $sql = "SELECT val FROM opt WHERE conf = 'dbversion'";
        try {
            $version = $this->queryValue($sql);
            return (int)$version;
        } catch (\PDOException $ignored) {
            // add the opt table - if this fails too, let the exception bubble up
            $sql = "CREATE TABLE IF NOT EXISTS opt (conf TEXT NOT NULL PRIMARY KEY, val NOT NULL DEFAULT '')";
            $this->pdo->exec($sql);
            $sql = "INSERT INTO opt (conf, val) VALUES ('dbversion', 0)";
            $this->pdo->exec($sql);
            return 0;
        }
    }

    /**
     * Get all schema files that have not been applied, yet
     *
     * @param int $current
     * @return array
     */
    protected function getMigrationsToApply(int $current): array
    {
        $files = glob($this->schemadir . '/*.sql');
        $upgrades = [];
        foreach ($files as $file) {
            $file = basename($file);
            if (!preg_match('/^(\d+)/', $file, $m)) continue;
            if ((int)$m[1] <= $current) continue;
            $upgrades[(int)$m[1]] = $file;
        }
        return $upgrades;
    }

    /**
     * Apply the migration in the given file, upgrading to the given version
     *
     * @param string $file
     * @param int $version
     */
    protected function applyMigration(string $file, int $version)
    {
        $sql = file_get_contents($this->schemadir . '/' . $file);

        $this->pdo->beginTransaction();
        try {
            $this->pdo->exec($sql);
            $st = $this->pdo->prepare('REPLACE INTO opt ("conf", "val") VALUES (:conf, :val)');
            $st->execute([':conf' => 'dbversion', ':val' => $version]);
            $this->pdo->commit();
        } catch (\PDOException $e) {
            $this->pdo->rollBack();
            throw $e;
        }
    }

    // endregion
}
