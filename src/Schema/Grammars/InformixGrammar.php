<?php

namespace Hanamichisakuragiking\LaravelInformix\Schema\Grammars;

use Illuminate\Database\Connection;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Schema\Grammars\Grammar;
use Illuminate\Support\Fluent;

class InformixGrammar extends Grammar
{
    /**
     * The possible column modifiers.
     *
     * @var string[]
     */
    protected $modifiers = ['Nullable', 'Default', 'Increment'];

    /**
     * The columns available as serials.
     *
     * @var string[]
     */
    protected $serials = ['bigInteger', 'integer', 'mediumInteger', 'smallInteger', 'tinyInteger'];

    /**
     * Compile a query to check if a table exists.
     *
     * @param  string  $schema
     * @param  string  $table
     * @return string
     */
    public function compileTableExists($schema, $table): string
    {
        return "SELECT tabname FROM systables WHERE tabtype = 'T' AND tabname = LOWER(?)";
    }

    /**
     * Compile a query to list all tables.
     *
     * @param  string  $schema
     * @return string
     */
    public function compileTables($schema): string
    {
        return "SELECT tabname AS name, created AS created_at FROM systables WHERE tabtype = 'T' AND tabname NOT LIKE 'sys%' ORDER BY tabname";
    }

    /**
     * Compile a query to list all views.
     *
     * @param  string  $schema
     * @return string
     */
    public function compileViews($schema): string
    {
        return "SELECT tabname AS name, created AS created_at FROM systables WHERE tabtype = 'V' AND tabname NOT LIKE 'sys%' ORDER BY tabname";
    }

    /**
     * Compile a query to list all columns of a table.
     *
     * @param  string  $schema
     * @param  string  $table
     * @return string
     */
    public function compileColumns($schema, $table): string
    {
        return "SELECT c.colname AS name, c.coltype AS type_name, c.collength AS length, "
             . "CASE WHEN MOD(c.coltype, 256) > 0 THEN 0 ELSE 1 END AS nullable "
             . "FROM syscolumns c "
             . "JOIN systables t ON c.tabid = t.tabid "
             . "WHERE t.tabname = LOWER(?) "
             . "ORDER BY c.colno";
    }

    /**
     * Compile a query to list all indexes of a table.
     *
     * @param  string  $schema
     * @param  string  $table
     * @return string
     */
    public function compileIndexes($schema, $table): string
    {
        return "SELECT i.idxname AS name, "
             . "i.idxtype AS unique, "
             . "CASE WHEN c.constrtype = 'P' THEN 1 ELSE 0 END AS primary, "
             . "TRIM(c1.colname) || CASE WHEN i.part2 > 0 THEN ',' || TRIM(c2.colname) ELSE '' END || "
             . "CASE WHEN i.part3 > 0 THEN ',' || TRIM(c3.colname) ELSE '' END AS columns "
             . "FROM sysindexes i "
             . "JOIN systables t ON i.tabid = t.tabid "
             . "JOIN syscolumns c1 ON c1.tabid = t.tabid AND c1.colno = i.part1 "
             . "LEFT JOIN syscolumns c2 ON c2.tabid = t.tabid AND c2.colno = i.part2 "
             . "LEFT JOIN syscolumns c3 ON c3.tabid = t.tabid AND c3.colno = i.part3 "
             . "LEFT JOIN sysconstraints c ON c.tabid = t.tabid AND c.idxname = i.idxname "
             . "WHERE t.tabname = LOWER('{$table}') "
             . "ORDER BY i.idxname";
    }

    /**
     * Compile a query to list all foreign keys of a table.
     *
     * @param  string  $schema
     * @param  string  $table
     * @return string
     */
    public function compileForeignKeys($schema, $table): string
    {
        return "SELECT c.constrname AS name, "
             . "TRIM(sc.colname) AS columns, "
             . "TRIM(rt.tabname) AS foreign_table, "
             . "TRIM(rc.colname) AS foreign_columns, "
             . "CASE r.delrule WHEN 'C' THEN 'CASCADE' WHEN 'A' THEN 'NO ACTION' WHEN 'N' THEN 'SET NULL' ELSE 'RESTRICT' END AS on_delete, "
             . "CASE r.updrule WHEN 'C' THEN 'CASCADE' WHEN 'A' THEN 'NO ACTION' WHEN 'N' THEN 'SET NULL' ELSE 'RESTRICT' END AS on_update "
             . "FROM sysconstraints c "
             . "JOIN systables t ON c.tabid = t.tabid "
             . "JOIN sysreferences r ON c.constrid = r.constrid "
             . "JOIN sysconstraints pc ON r.primary = pc.constrid "
             . "JOIN systables rt ON pc.tabid = rt.tabid "
             . "JOIN sysindexes si ON c.idxname = si.idxname AND si.tabid = t.tabid "
             . "JOIN syscolumns sc ON sc.tabid = t.tabid AND sc.colno = si.part1 "
             . "JOIN sysindexes pi ON pc.idxname = pi.idxname AND pi.tabid = rt.tabid "
             . "JOIN syscolumns rc ON rc.tabid = rt.tabid AND rc.colno = pi.part1 "
             . "WHERE c.constrtype = 'R' AND t.tabname = LOWER('{$table}') "
             . "ORDER BY c.constrname";
    }

    /**
     * Compile a create table command.
     *
     * @param  Blueprint  $blueprint
     * @param  Fluent  $command
     * @return string
     */
    public function compileCreate(Blueprint $blueprint, Fluent $command): string
    {
        $columns = implode(', ', $this->getColumns($blueprint));

        $sql = 'CREATE TABLE ' . $this->wrapTable($blueprint) . " ({$columns})";

        // Note: Do NOT add primary keys or foreign keys here.
        // They will be added via separate ALTER TABLE commands.
        // This avoids conflicts with Laravel's command processing.

        return $sql;
    }

    /**
     * Compile a drop table command.
     *
     * @param  Blueprint  $blueprint
     * @param  Fluent  $command
     * @return string
     */
    public function compileDrop(Blueprint $blueprint, Fluent $command): string
    {
        return 'DROP TABLE ' . $this->wrapTable($blueprint);
    }

    /**
     * Compile a drop table (if exists) command.
     * Informix doesn't support IF EXISTS, so we need a workaround.
     *
     * @param  Blueprint  $blueprint
     * @param  Fluent  $command
     * @return string
     */
    public function compileDropIfExists(Blueprint $blueprint, Fluent $command): string
    {
        // This will be handled specially in the Schema Builder
        // by checking systables first
        return 'DROP TABLE ' . $this->wrapTable($blueprint);
    }

    /**
     * Compile an add column command.
     *
     * @param  Blueprint  $blueprint
     * @param  Fluent  $command
     * @return string
     */
    public function compileAdd(Blueprint $blueprint, Fluent $command): string
    {
        $columns = implode(', ', $this->getColumns($blueprint));

        return 'ALTER TABLE ' . $this->wrapTable($blueprint) . " ADD ({$columns})";
    }

    /**
     * Compile a drop column command.
     *
     * @param  Blueprint  $blueprint
     * @param  Fluent  $command
     * @return string
     */
    public function compileDropColumn(Blueprint $blueprint, Fluent $command): string
    {
        $columns = $this->prefixArray('DROP', $this->wrapArray($command->columns));

        return 'ALTER TABLE ' . $this->wrapTable($blueprint) . ' ' . implode(', ', $columns);
    }

    /**
     * Compile a rename column command.
     *
     * @param  Blueprint  $blueprint
     * @param  Fluent  $command
     * @return string
     */
    public function compileRenameColumn(Blueprint $blueprint, Fluent $command): string
    {
        return sprintf(
            'RENAME COLUMN %s.%s TO %s',
            $this->wrapTable($blueprint),
            $this->wrap($command->from),
            $this->wrap($command->to)
        );
    }

    /**
     * Compile a primary key command.
     *
     * @param  Blueprint  $blueprint
     * @param  Fluent  $command
     * @return string
     */
    public function compilePrimary(Blueprint $blueprint, Fluent $command): string
    {
        $columns = $this->columnize($command->columns);
        $table = $this->wrapTable($blueprint);

        return "ALTER TABLE {$table} ADD CONSTRAINT PRIMARY KEY ({$columns}) CONSTRAINT {$command->index}";
    }

    /**
     * Compile a unique key command.
     *
     * @param  Blueprint  $blueprint
     * @param  Fluent  $command
     * @return string
     */
    public function compileUnique(Blueprint $blueprint, Fluent $command): string
    {
        $columns = $this->columnize($command->columns);
        $table = $this->wrapTable($blueprint);

        return "ALTER TABLE {$table} ADD CONSTRAINT UNIQUE ({$columns}) CONSTRAINT {$command->index}";
    }

    /**
     * Compile a plain index key command.
     *
     * @param  Blueprint  $blueprint
     * @param  Fluent  $command
     * @return string
     */
    public function compileIndex(Blueprint $blueprint, Fluent $command): string
    {
        $columns = $this->columnize($command->columns);
        $table = $this->wrapTable($blueprint);

        return "CREATE INDEX {$command->index} ON {$table} ({$columns})";
    }

    /**
     * Compile a drop index command.
     *
     * @param  Blueprint  $blueprint
     * @param  Fluent  $command
     * @return string
     */
    public function compileDropIndex(Blueprint $blueprint, Fluent $command): string
    {
        return "DROP INDEX {$command->index}";
    }

    /**
     * Compile a foreign key command.
     *
     * @param  Blueprint  $blueprint
     * @param  Fluent  $command
     * @return string
     */
    public function compileForeign(Blueprint $blueprint, Fluent $command): string
    {
        $table = $this->wrapTable($blueprint);
        $on = $this->wrapTable($command->on);
        $columns = $this->columnize($command->columns);
        $onColumns = $this->columnize((array) $command->references);

        $sql = "ALTER TABLE {$table} ADD CONSTRAINT FOREIGN KEY ({$columns}) REFERENCES {$on} ({$onColumns})";

        if (!is_null($command->onDelete)) {
            $sql .= " ON DELETE {$command->onDelete}";
        }

        $sql .= " CONSTRAINT {$command->index}";

        return $sql;
    }

    /**
     * Compile a drop foreign key command.
     *
     * @param  Blueprint  $blueprint
     * @param  Fluent  $command
     * @return string
     */
    public function compileDropForeign(Blueprint $blueprint, Fluent $command): string
    {
        $table = $this->wrapTable($blueprint);

        return "ALTER TABLE {$table} DROP CONSTRAINT {$command->index}";
    }

    /**
     * Compile a rename table command.
     *
     * @param  Blueprint  $blueprint
     * @param  Fluent  $command
     * @return string
     */
    public function compileRename(Blueprint $blueprint, Fluent $command): string
    {
        $from = $this->wrapTable($blueprint);
        $to = $this->wrapTable($command->to);

        return "RENAME TABLE {$from} TO {$to}";
    }

    /**
     * Wrap a single string in keyword identifiers.
     *
     * @param  string  $value
     * @return string
     */
    protected function wrapValue($value): string
    {
        if ($value === '*') {
            return $value;
        }

        return $value;
    }

    /*
    |--------------------------------------------------------------------------
    | Column Type Definitions
    |--------------------------------------------------------------------------
    */

    /**
     * Create the column definition for a char type.
     *
     * @param  Fluent  $column
     * @return string
     */
    protected function typeChar(Fluent $column): string
    {
        $length = min($column->length ?? 255, 255);
        return "CHAR({$length})";
    }

    /**
     * Create the column definition for a string type.
     *
     * @param  Fluent  $column
     * @return string
     */
    protected function typeString(Fluent $column): string
    {
        $length = $column->length ?? 255;

        if ($length <= 255) {
            return "VARCHAR({$length})";
        } elseif ($length <= 32739) {
            return "LVARCHAR({$length})";
        }

        return 'LVARCHAR(32739)';
    }

    /**
     * Create the column definition for a tiny text type.
     *
     * @param  Fluent  $column
     * @return string
     */
    protected function typeTinyText(Fluent $column): string
    {
        return 'VARCHAR(255)';
    }

    /**
     * Create the column definition for a text type.
     *
     * @param  Fluent  $column
     * @return string
     */
    protected function typeText(Fluent $column): string
    {
        return 'TEXT';
    }

    /**
     * Create the column definition for a medium text type.
     *
     * @param  Fluent  $column
     * @return string
     */
    protected function typeMediumText(Fluent $column): string
    {
        return 'TEXT';
    }

    /**
     * Create the column definition for a long text type.
     *
     * @param  Fluent  $column
     * @return string
     */
    protected function typeLongText(Fluent $column): string
    {
        return 'TEXT';
    }

    /**
     * Create the column definition for an integer type.
     *
     * @param  Fluent  $column
     * @return string
     */
    protected function typeInteger(Fluent $column): string
    {
        return $column->autoIncrement ? 'SERIAL' : 'INTEGER';
    }

    /**
     * Create the column definition for a big integer type.
     *
     * @param  Fluent  $column
     * @return string
     */
    protected function typeBigInteger(Fluent $column): string
    {
        return $column->autoIncrement ? 'SERIAL8' : 'INT8';
    }

    /**
     * Create the column definition for a medium integer type.
     *
     * @param  Fluent  $column
     * @return string
     */
    protected function typeMediumInteger(Fluent $column): string
    {
        return $column->autoIncrement ? 'SERIAL' : 'INTEGER';
    }

    /**
     * Create the column definition for a small integer type.
     *
     * @param  Fluent  $column
     * @return string
     */
    protected function typeSmallInteger(Fluent $column): string
    {
        return 'SMALLINT';
    }

    /**
     * Create the column definition for a tiny integer type.
     *
     * @param  Fluent  $column
     * @return string
     */
    protected function typeTinyInteger(Fluent $column): string
    {
        return 'SMALLINT';
    }

    /**
     * Create the column definition for a float type.
     *
     * @param  Fluent  $column
     * @return string
     */
    protected function typeFloat(Fluent $column): string
    {
        $total = $column->total ?? 8;
        $places = $column->places ?? 2;

        return "DECIMAL({$total},{$places})";
    }

    /**
     * Create the column definition for a double type.
     *
     * @param  Fluent  $column
     * @return string
     */
    protected function typeDouble(Fluent $column): string
    {
        $total = $column->total ?? 16;
        $places = $column->places ?? 4;

        return "DECIMAL({$total},{$places})";
    }

    /**
     * Create the column definition for a decimal type.
     *
     * @param  Fluent  $column
     * @return string
     */
    protected function typeDecimal(Fluent $column): string
    {
        return "DECIMAL({$column->total},{$column->places})";
    }

    /**
     * Create the column definition for a boolean type.
     *
     * @param  Fluent  $column
     * @return string
     */
    protected function typeBoolean(Fluent $column): string
    {
        return 'CHAR(1)';
    }

    /**
     * Create the column definition for an enumeration type.
     *
     * @param  Fluent  $column
     * @return string
     */
    protected function typeEnum(Fluent $column): string
    {
        // Informix doesn't have ENUM, use VARCHAR
        return 'VARCHAR(255)';
    }

    /**
     * Create the column definition for a json type.
     *
     * @param  Fluent  $column
     * @return string
     */
    protected function typeJson(Fluent $column): string
    {
        return 'TEXT';
    }

    /**
     * Create the column definition for a jsonb type.
     *
     * @param  Fluent  $column
     * @return string
     */
    protected function typeJsonb(Fluent $column): string
    {
        return 'TEXT';
    }

    /**
     * Create the column definition for a date type.
     *
     * @param  Fluent  $column
     * @return string
     */
    protected function typeDate(Fluent $column): string
    {
        return 'DATE';
    }

    /**
     * Create the column definition for a date-time type.
     *
     * @param  Fluent  $column
     * @return string
     */
    protected function typeDateTime(Fluent $column): string
    {
        return 'DATETIME YEAR TO SECOND';
    }

    /**
     * Create the column definition for a date-time (with time zone) type.
     *
     * @param  Fluent  $column
     * @return string
     */
    protected function typeDateTimeTz(Fluent $column): string
    {
        return 'DATETIME YEAR TO SECOND';
    }

    /**
     * Create the column definition for a time type.
     *
     * @param  Fluent  $column
     * @return string
     */
    protected function typeTime(Fluent $column): string
    {
        return 'DATETIME HOUR TO SECOND';
    }

    /**
     * Create the column definition for a time (with time zone) type.
     *
     * @param  Fluent  $column
     * @return string
     */
    protected function typeTimeTz(Fluent $column): string
    {
        return 'DATETIME HOUR TO SECOND';
    }

    /**
     * Create the column definition for a timestamp type.
     *
     * @param  Fluent  $column
     * @return string
     */
    protected function typeTimestamp(Fluent $column): string
    {
        return 'DATETIME YEAR TO SECOND';
    }

    /**
     * Create the column definition for a timestamp (with time zone) type.
     *
     * @param  Fluent  $column
     * @return string
     */
    protected function typeTimestampTz(Fluent $column): string
    {
        return 'DATETIME YEAR TO SECOND';
    }

    /**
     * Create the column definition for a year type.
     *
     * @param  Fluent  $column
     * @return string
     */
    protected function typeYear(Fluent $column): string
    {
        return 'SMALLINT';
    }

    /**
     * Create the column definition for a binary type.
     *
     * @param  Fluent  $column
     * @return string
     */
    protected function typeBinary(Fluent $column): string
    {
        return 'BYTE';
    }

    /**
     * Create the column definition for a uuid type.
     *
     * @param  Fluent  $column
     * @return string
     */
    protected function typeUuid(Fluent $column): string
    {
        return 'CHAR(36)';
    }

    /**
     * Create the column definition for an IP address type.
     *
     * @param  Fluent  $column
     * @return string
     */
    protected function typeIpAddress(Fluent $column): string
    {
        return 'VARCHAR(45)';
    }

    /**
     * Create the column definition for a MAC address type.
     *
     * @param  Fluent  $column
     * @return string
     */
    protected function typeMacAddress(Fluent $column): string
    {
        return 'VARCHAR(17)';
    }

    /*
    |--------------------------------------------------------------------------
    | Column Modifiers
    |--------------------------------------------------------------------------
    */

    /**
     * Get the SQL for a nullable column modifier.
     *
     * @param  Blueprint  $blueprint
     * @param  Fluent  $column
     * @return string|null
     */
    protected function modifyNullable(Blueprint $blueprint, Fluent $column): ?string
    {
        if ($column->autoIncrement) {
            return null;
        }

        return $column->nullable ? '' : ' NOT NULL';
    }

    /**
     * Get the SQL for a default column modifier.
     *
     * @param  Blueprint  $blueprint
     * @param  Fluent  $column
     * @return string|null
     */
    protected function modifyDefault(Blueprint $blueprint, Fluent $column): ?string
    {
        if (!is_null($column->default)) {
            return ' DEFAULT ' . $this->getDefaultValue($column->default);
        }

        return null;
    }

    /**
     * Get the SQL for an auto-increment column modifier.
     *
     * @param  Blueprint  $blueprint
     * @param  Fluent  $column
     * @return string|null
     */
    protected function modifyIncrement(Blueprint $blueprint, Fluent $column): ?string
    {
        if (in_array($column->type, $this->serials) && $column->autoIncrement) {
            // SERIAL is already in the type definition, add primary key
            return ' PRIMARY KEY';
        }

        return null;
    }

    /**
     * Format a value so that it can be used in "default" clauses.
     *
     * @param  mixed  $value
     * @return string
     */
    protected function getDefaultValue($value): string
    {
        if ($value instanceof \Illuminate\Database\Query\Expression) {
            return (string) $value;
        }

        if (is_bool($value)) {
            return $value ? "'t'" : "'f'";
        }

        if (is_string($value)) {
            return "'" . str_replace("'", "''", $value) . "'";
        }

        return (string) $value;
    }
}
