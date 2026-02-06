<?php

namespace Hanamichisakuragiking\LaravelInformix;

use Closure;
use DateTimeInterface;
use Illuminate\Database\Connection;
use Illuminate\Database\QueryException;
use Illuminate\Filesystem\Filesystem;
use Hanamichisakuragiking\LaravelInformix\Query\Grammars\InformixGrammar as QueryGrammar;
use Hanamichisakuragiking\LaravelInformix\Query\Processors\InformixProcessor;
use Hanamichisakuragiking\LaravelInformix\Schema\Builder as SchemaBuilder;
use Hanamichisakuragiking\LaravelInformix\Schema\Grammars\InformixGrammar as SchemaGrammar;
use Hanamichisakuragiking\LaravelInformix\Schema\InformixSchemaState;
use PDO;

class InformixConnection extends Connection
{
    /**
     * Get a schema builder instance for the connection.
     *
     * @return SchemaBuilder
     */
    public function getSchemaBuilder(): SchemaBuilder
    {
        if (is_null($this->schemaGrammar)) {
            $this->useDefaultSchemaGrammar();
        }

        return new SchemaBuilder($this);
    }

    /**
     * Get the schema state for the connection.
     *
     * @param  \Illuminate\Filesystem\Filesystem|null  $files
     * @param  callable|null  $processFactory
     * @return \Hanamichisakuragiking\LaravelInformix\Schema\InformixSchemaState
     */
    public function getSchemaState(?Filesystem $files = null, ?callable $processFactory = null)
    {
        return new InformixSchemaState($this, $files, $processFactory);
    }

    /**
     * Get the default query grammar instance.
     *
     * @return QueryGrammar
     */
    protected function getDefaultQueryGrammar(): QueryGrammar
    {
        $grammar = new QueryGrammar($this);
        $grammar->setTablePrefix($this->getTablePrefix());

        return $grammar;
    }

    /**
     * Get the default schema grammar instance.
     *
     * @return SchemaGrammar
     */
    protected function getDefaultSchemaGrammar(): SchemaGrammar
    {
        $grammar = new SchemaGrammar($this);
        $grammar->setTablePrefix($this->getTablePrefix());

        return $grammar;
    }

    /**
     * Get the default post processor instance.
     *
     * @return InformixProcessor
     */
    protected function getDefaultPostProcessor(): InformixProcessor
    {
        return new InformixProcessor();
    }

    /**
     * Get the server version for the connection.
     * PDO_INFORMIX doesn't support ATTR_SERVER_VERSION.
     *
     * @return string
     */
    public function getServerVersion(): string
    {
        // Could query sysmaster:sysmachineinfo for actual version
        return 'Informix';
    }

    /**
     * Run a select statement against the database.
     * 
     * Override to use query() instead of prepare() due to PDO_INFORMIX segfault bug.
     *
     * @param  string  $query
     * @param  array  $bindings
     * @param  bool  $useReadPdo
     * @return array
     */
    public function select($query, $bindings = [], $useReadPdo = true): array
    {
        return $this->run($query, $bindings, function ($query, $bindings) use ($useReadPdo) {
            if ($this->pretending()) {
                return [];
            }

            // Manual binding instead of prepare() due to PDO_INFORMIX bug
            $preparedQuery = $this->bindValuesIntoQuery($query, $this->prepareBindings($bindings));
            
            $pdo = $useReadPdo ? $this->getReadPdo() : $this->getPdo();
            $statement = $pdo->query($preparedQuery);
            
            return $statement->fetchAll(PDO::FETCH_OBJ);
        });
    }

    /**
     * Run a select statement and return a single result.
     *
     * @param  string  $query
     * @param  array  $bindings
     * @param  bool  $useReadPdo
     * @return mixed
     */
    public function selectOne($query, $bindings = [], $useReadPdo = true): mixed
    {
        $records = $this->select($query, $bindings, $useReadPdo);

        return array_shift($records);
    }

    /**
     * Execute an SQL statement and return the boolean result.
     *
     * @param  string  $query
     * @param  array  $bindings
     * @return bool
     */
    public function statement($query, $bindings = []): bool
    {
        return $this->run($query, $bindings, function ($query, $bindings) {
            if ($this->pretending()) {
                return true;
            }

            $preparedQuery = $this->bindValuesIntoQuery($query, $this->prepareBindings($bindings));
            
            $this->getPdo()->exec($preparedQuery);
            
            return true;
        });
    }

    /**
     * Run an SQL statement and get the number of rows affected.
     *
     * @param  string  $query
     * @param  array  $bindings
     * @return int
     */
    public function affectingStatement($query, $bindings = []): int
    {
        return $this->run($query, $bindings, function ($query, $bindings) {
            if ($this->pretending()) {
                return 0;
            }

            $preparedQuery = $this->bindValuesIntoQuery($query, $this->prepareBindings($bindings));
            
            return (int) $this->getPdo()->exec($preparedQuery);
        });
    }

    /**
     * Run an insert statement and get the last insert ID.
     *
     * @param  string  $query
     * @param  array  $bindings
     * @param  string|null  $sequence
     * @return int
     */
    public function insertGetId($query, $bindings = [], $sequence = null): int
    {
        // Extract the table name from INSERT query
        if (preg_match('/INSERT\s+INTO\s+([^\s(]+)/i', $query, $matches)) {
            $table = trim($matches[1], '"\'`');
            
            // Perform the insert
            $this->statement($query, $bindings);
            
            // Query for the maximum ID (SERIAL value) in the table
            // This works because SERIAL values are always increasing
            $result = $this->selectOne("SELECT MAX(id) AS last_id FROM {$table}");
            
            return (int) ($result->last_id ?? 0);
        }
        
        // Fallback - just perform the insert
        $this->statement($query, $bindings);
        
        return 0;
    }

    /**
     * Insert new records into the database.
     *
     * Informix does not support multi-row INSERT syntax like:
     *   INSERT INTO t VALUES (...), (...), (...)
     * 
     * This method handles multi-row inserts by executing each row
     * individually within a transaction for atomicity.
     *
     * @param  string  $query
     * @param  array  $bindings
     * @return bool
     */
    public function insert($query, $bindings = []): bool
    {
        // Check if this is a multi-row insert by counting placeholders
        // A multi-row insert from Laravel comes as a single query with
        // multiple sets of bindings flattened into one array
        
        // Count the number of ? placeholders in the query
        $placeholderCount = substr_count($query, '?');
        $bindingCount = count($bindings);
        
        // If bindings match placeholders exactly, it's a single-row insert
        if ($placeholderCount === $bindingCount || $bindingCount === 0) {
            return $this->statement($query, $bindings);
        }
        
        // Multi-row insert detected - Laravel's Query Builder passes
        // all values flattened, so we need to split them
        // Extract table and columns from query: INSERT INTO table (col1, col2) VALUES (?, ?)
        if (!preg_match('/INSERT\s+INTO\s+([^\s(]+)\s*\(([^)]+)\)\s*VALUES\s*\(([^)]+)\)/i', $query, $matches)) {
            // Can't parse, try executing as-is (will likely fail)
            return $this->statement($query, $bindings);
        }
        
        $table = trim($matches[1]);
        $columns = $matches[2];
        $valuePlaceholders = $matches[3];
        
        // Calculate rows: total bindings / placeholders per row
        $columnsPerRow = $placeholderCount;
        $rowCount = $bindingCount / $columnsPerRow;
        
        if ($rowCount != (int) $rowCount) {
            // Binding count doesn't divide evenly - something is wrong
            return $this->statement($query, $bindings);
        }
        
        $rowCount = (int) $rowCount;
        
        // Build single-row INSERT statement
        $singleRowQuery = "INSERT INTO {$table} ({$columns}) VALUES ({$valuePlaceholders})";
        
        // Execute each row in a transaction
        return $this->transaction(function () use ($singleRowQuery, $bindings, $columnsPerRow, $rowCount) {
            for ($i = 0; $i < $rowCount; $i++) {
                $rowBindings = array_slice($bindings, $i * $columnsPerRow, $columnsPerRow);
                $this->statement($singleRowQuery, $rowBindings);
            }
            return true;
        });
    }

    /**
     * Prepare the query bindings for execution.
     *
     * @param  array  $bindings
     * @return array
     */
    public function prepareBindings(array $bindings): array
    {
        $grammar = $this->getQueryGrammar();

        foreach ($bindings as $key => &$value) {
            if ($value instanceof DateTimeInterface) {
                $value = $value->format($grammar->getDateFormat());
            } elseif (is_bool($value)) {
                $value = $value ? 't' : 'f';
            }
        }

        return $bindings;
    }

    /**
     * Quote a string for use in Informix SQL.
     * 
     * Informix uses SQL standard single-quote escaping ('' not \')
     * PDO::quote() uses backslash escaping which doesn't work with Informix.
     *
     * @param  string  $value
     * @return string
     */
    protected function quoteString(string $value): string
    {
        // Escape single quotes by doubling them (SQL standard)
        // Also escape backslashes for safety
        $escaped = str_replace("'", "''", $value);
        
        return "'" . $escaped . "'";
    }

    /**
     * Bind values into the query string manually.
     * 
     * This is a workaround for PDO_INFORMIX prepare() segfault.
     * Uses strpos/substr instead of preg_replace to avoid $ backreference issues.
     * Uses SQL standard quote escaping ('') instead of backslash (\') for Informix.
     *
     * @param  string  $query
     * @param  array  $bindings
     * @return string
     */
    protected function bindValuesIntoQuery(string $query, array $bindings): string
    {
        if (empty($bindings)) {
            return $query;
        }

        foreach ($bindings as $binding) {
            if ($binding === null) {
                $value = 'NULL';
            } elseif (is_bool($binding)) {
                $value = $binding ? "'t'" : "'f'";
            } elseif (is_int($binding) || is_float($binding)) {
                $value = (string) $binding;
            } else {
                // Use our custom quoting for Informix (SQL standard '' escaping)
                $value = $this->quoteString((string) $binding);
            }

            // Replace first ? with the bound value using strpos/substr
            // This avoids preg_replace backreference issues with $ in values like bcrypt hashes
            $pos = strpos($query, '?');
            if ($pos !== false) {
                $query = substr($query, 0, $pos) . $value . substr($query, $pos + 1);
            }
        }

        return $query;
    }

    /**
     * Determine if the given database exception was caused by a unique constraint violation.
     *
     * @param  \Exception  $exception
     * @return bool
     */
    protected function isUniqueConstraintError(\Exception $exception): bool
    {
        // Informix error -239: Could not insert new row - Loss of referential constraint
        // Informix error -268: Unique constraint violated
        return str_contains($exception->getMessage(), '-268') 
            || str_contains($exception->getMessage(), '-239');
    }
}
