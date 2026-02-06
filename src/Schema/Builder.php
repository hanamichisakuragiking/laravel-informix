<?php

namespace Hanamichisakuragiking\LaravelInformix\Schema;

use Closure;
use Illuminate\Database\Schema\Builder as BaseBuilder;

class Builder extends BaseBuilder
{
    /**
     * Determine if the given table exists.
     *
     * @param  string  $table
     * @return bool
     */
    public function hasTable($table): bool
    {
        $table = $this->connection->getTablePrefix() . $table;

        $result = $this->connection->selectOne(
            $this->grammar->compileTableExists('', $table),
            [$table]
        );

        return !is_null($result);
    }

    /**
     * Get the column listing for a given table.
     *
     * @param  string  $table
     * @return array
     */
    public function getColumnListing($table): array
    {
        $table = $this->connection->getTablePrefix() . $table;

        $results = $this->connection->select(
            $this->grammar->compileColumns('', $table),
            [$table]
        );

        return array_map(function ($result) {
            return $result->name;
        }, $results);
    }

    /**
     * Determine if the given table has a given column.
     *
     * @param  string  $table
     * @param  string  $column
     * @return bool
     */
    public function hasColumn($table, $column): bool
    {
        return in_array(
            strtolower($column),
            array_map('strtolower', $this->getColumnListing($table))
        );
    }

    /**
     * Drop a table from the schema if it exists.
     * Informix doesn't support IF EXISTS, so check first.
     *
     * @param  string  $table
     * @return void
     */
    public function dropIfExists($table): void
    {
        if ($this->hasTable($table)) {
            $this->drop($table);
        }
    }

    /**
     * Drop all tables from the database.
     *
     * @return void
     */
    public function dropAllTables(): void
    {
        $tables = $this->getTables();

        if (empty($tables)) {
            return;
        }

        // Disable foreign key checks by dropping in reverse order
        // Get tables with their dependencies
        $tableNames = array_map(fn($t) => $t['name'], $tables);

        // Drop tables - try multiple passes to handle foreign key dependencies
        $maxAttempts = 5;
        $attempt = 0;

        while (!empty($tableNames) && $attempt < $maxAttempts) {
            $attempt++;
            $failed = [];

            foreach ($tableNames as $table) {
                try {
                    $this->connection->statement("DROP TABLE {$table}");
                } catch (\Exception $e) {
                    // Table might have dependencies, try again later
                    $failed[] = $table;
                }
            }

            $tableNames = $failed;
        }

        // If there are still tables left, throw an error
        if (!empty($tableNames)) {
            throw new \RuntimeException(
                'Unable to drop all tables. Remaining: ' . implode(', ', $tableNames)
            );
        }
    }

    /**
     * Drop all views from the database.
     *
     * @return void
     */
    public function dropAllViews(): void
    {
        $views = $this->getViews();

        foreach ($views as $view) {
            $this->connection->statement("DROP VIEW {$view['name']}");
        }
    }

    /**
     * Get all of the table names for the database.
     *
     * @param  string|null  $schema
     * @return array
     */
    public function getTables($schema = null): array
    {
        $results = $this->connection->select(
            $this->grammar->compileTables($schema ?? '')
        );

        // Convert to array format expected by Laravel's ShowCommand
        // Note: Informix returns column names in UPPERCASE
        return array_map(function ($table) {
            if (is_object($table)) {
                $name = $table->NAME ?? $table->name ?? '';
            } else {
                $name = $table['NAME'] ?? $table['name'] ?? '';
            }
            return [
                'name' => $name,
                'schema' => null,
                'schema_qualified_name' => $name,
                'size' => null,
                'comment' => null,
                'collation' => null,
                'engine' => null,
            ];
        }, $results);
    }

    /**
     * Get all of the view names for the database.
     *
     * @param  string|null  $schema
     * @return array
     */
    public function getViews($schema = null): array
    {
        $results = $this->connection->select(
            $this->grammar->compileViews($schema ?? '')
        );

        // Convert to array format expected by Laravel
        // Note: Informix returns column names in UPPERCASE
        return array_map(function ($view) {
            if (is_object($view)) {
                $name = $view->NAME ?? $view->name ?? '';
            } else {
                $name = $view['NAME'] ?? $view['name'] ?? '';
            }
            return [
                'name' => $name,
                'schema' => null,
                'schema_qualified_name' => $name,
                'definition' => null,
            ];
        }, $results);
    }

    /**
     * Get the columns for a given table.
     *
     * @param  string  $table
     * @return array
     */
    public function getColumns($table): array
    {
        $table = $this->connection->getTablePrefix() . $table;

        $results = $this->connection->select(
            $this->grammar->compileColumns('', $table),
            [$table]
        );

        // Convert to array format expected by Laravel's TableCommand
        return array_map(function ($column) {
            // Handle both object and array results, with UPPERCASE column names
            if (is_object($column)) {
                $name = $column->name ?? $column->NAME ?? '';
                $typeName = $column->type_name ?? $column->TYPE_NAME ?? '';
                $nullable = $column->nullable ?? $column->NULLABLE ?? 0;
                $length = $column->length ?? $column->LENGTH ?? null;
            } else {
                $name = $column['name'] ?? $column['NAME'] ?? '';
                $typeName = $column['type_name'] ?? $column['TYPE_NAME'] ?? '';
                $nullable = $column['nullable'] ?? $column['NULLABLE'] ?? 0;
                $length = $column['length'] ?? $column['LENGTH'] ?? null;
            }

            // Map Informix type codes to type names if needed
            $type = $this->mapInformixType($typeName, $length);

            return [
                'name' => $name,
                'type_name' => $type,
                'type' => $type,
                'collation' => null,
                'nullable' => (bool) $nullable,
                'default' => null,
                'auto_increment' => str_contains(strtolower($type), 'serial'),
                'comment' => null,
                'generation' => null,
            ];
        }, $results);
    }

    /**
     * Map Informix column type code to readable type name.
     *
     * @param  mixed  $typeCode
     * @param  mixed  $length
     * @return string
     */
    protected function mapInformixType($typeCode, $length = null): string
    {
        // If it's already a string type name, return it
        if (!is_numeric($typeCode)) {
            return (string) $typeCode;
        }

        // Informix type codes (from syscolumns.coltype)
        $types = [
            0 => 'char',
            1 => 'smallint',
            2 => 'integer',
            3 => 'float',
            4 => 'smallfloat',
            5 => 'decimal',
            6 => 'serial',
            7 => 'date',
            8 => 'money',
            9 => 'null',
            10 => 'datetime',
            11 => 'byte',
            12 => 'text',
            13 => 'varchar',
            14 => 'interval',
            15 => 'nchar',
            16 => 'nvarchar',
            17 => 'int8',
            18 => 'serial8',
            19 => 'set',
            20 => 'multiset',
            21 => 'list',
            22 => 'row',
            23 => 'collection',
            40 => 'lvarchar',
            41 => 'boolean',
            43 => 'bigint',
            44 => 'bigserial',
            52 => 'bigint',
            53 => 'bigserial',
            256 => 'char not null',
            262 => 'serial not null',
        ];

        $baseType = $typeCode % 256;
        $typeName = $types[$baseType] ?? "type_{$typeCode}";

        if ($length && in_array($baseType, [0, 13, 15, 16, 40])) {
            $typeName .= "({$length})";
        }

        return $typeName;
    }

    /**
     * Get the indexes for a given table.
     *
     * @param  string  $table
     * @return array
     */
    public function getIndexes($table): array
    {
        $table = $this->connection->getTablePrefix() . $table;

        $results = $this->connection->select(
            $this->grammar->compileIndexes('', $table)
        );

        return array_map(function ($index) {
            if (is_object($index)) {
                $name = $index->name ?? $index->NAME ?? '';
                $columns = $index->columns ?? $index->COLUMNS ?? '';
                $unique = $index->unique ?? $index->UNIQUE ?? 0;
                $primary = $index->primary ?? $index->PRIMARY ?? 0;
            } else {
                $name = $index['name'] ?? $index['NAME'] ?? '';
                $columns = $index['columns'] ?? $index['COLUMNS'] ?? '';
                $unique = $index['unique'] ?? $index['UNIQUE'] ?? 0;
                $primary = $index['primary'] ?? $index['PRIMARY'] ?? 0;
            }

            return [
                'name' => $name,
                'columns' => explode(',', $columns),
                'type' => null,
                'unique' => (bool) $unique,
                'primary' => (bool) $primary,
            ];
        }, $results);
    }

    /**
     * Get the foreign keys for a given table.
     *
     * @param  string  $table
     * @return array
     */
    public function getForeignKeys($table): array
    {
        $table = $this->connection->getTablePrefix() . $table;

        $results = $this->connection->select(
            $this->grammar->compileForeignKeys('', $table)
        );

        return array_map(function ($fk) {
            if (is_object($fk)) {
                $name = $fk->name ?? $fk->NAME ?? '';
                $columns = $fk->columns ?? $fk->COLUMNS ?? '';
                $foreignSchema = $fk->foreign_schema ?? $fk->FOREIGN_SCHEMA ?? '';
                $foreignTable = $fk->foreign_table ?? $fk->FOREIGN_TABLE ?? '';
                $foreignColumns = $fk->foreign_columns ?? $fk->FOREIGN_COLUMNS ?? '';
                $onUpdate = $fk->on_update ?? $fk->ON_UPDATE ?? 'NO ACTION';
                $onDelete = $fk->on_delete ?? $fk->ON_DELETE ?? 'NO ACTION';
            } else {
                $name = $fk['name'] ?? $fk['NAME'] ?? '';
                $columns = $fk['columns'] ?? $fk['COLUMNS'] ?? '';
                $foreignSchema = $fk['foreign_schema'] ?? $fk['FOREIGN_SCHEMA'] ?? '';
                $foreignTable = $fk['foreign_table'] ?? $fk['FOREIGN_TABLE'] ?? '';
                $foreignColumns = $fk['foreign_columns'] ?? $fk['FOREIGN_COLUMNS'] ?? '';
                $onUpdate = $fk['on_update'] ?? $fk['ON_UPDATE'] ?? 'NO ACTION';
                $onDelete = $fk['on_delete'] ?? $fk['ON_DELETE'] ?? 'NO ACTION';
            }

            return [
                'name' => $name,
                'columns' => $columns ? explode(',', $columns) : [],
                'foreign_schema' => $foreignSchema,
                'foreign_table' => $foreignTable,
                'foreign_columns' => $foreignColumns ? explode(',', $foreignColumns) : [],
                'on_update' => $onUpdate,
                'on_delete' => $onDelete,
            ];
        }, $results);
    }

    /**
     * Create a new command set with a Closure.
     *
     * @param  string  $table
     * @param  \Closure|null  $callback
     * @return \Illuminate\Database\Schema\Blueprint
     */
    protected function createBlueprint($table, Closure $callback = null)
    {
        // You could extend Blueprint here if needed for Informix-specific features
        return parent::createBlueprint($table, $callback);
    }
}
