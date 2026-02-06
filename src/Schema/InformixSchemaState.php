<?php

namespace Hanamichisakuragiking\LaravelInformix\Schema;

use Illuminate\Database\Connection;
use Illuminate\Database\Schema\SchemaState;
use Illuminate\Filesystem\Filesystem;

class InformixSchemaState extends SchemaState
{
    /**
     * Dump the database's schema into a file.
     *
     * @param  \Illuminate\Database\Connection  $connection
     * @param  string  $path
     * @return void
     */
    public function dump(Connection $connection, $path)
    {
        $schema = $this->getSchemaDefinitions($connection);
        
        $this->files->put($path, $schema);
        
        if ($this->hasMigrationTable()) {
            $this->appendMigrationData($connection, $path);
        }
    }

    /**
     * Get all schema definitions from the database.
     *
     * @param  \Illuminate\Database\Connection  $connection
     * @return string
     */
    protected function getSchemaDefinitions(Connection $connection): string
    {
        $output = "-- Informix Schema Dump\n";
        $output .= "-- Generated: " . date('Y-m-d H:i:s') . "\n";
        $output .= "-- Database: " . $connection->getDatabaseName() . "\n\n";

        // Get all tables
        $tables = $connection->select("
            SELECT tabname 
            FROM systables 
            WHERE tabid >= 100 
              AND tabtype = 'T'
            ORDER BY tabname
        ");

        foreach ($tables as $table) {
            $tableName = is_object($table) ? ($table->tabname ?? $table->TABNAME) : ($table['tabname'] ?? $table['TABNAME']);
            $output .= $this->getTableSchema($connection, $tableName);
        }

        return $output;
    }

    /**
     * Get the CREATE TABLE statement for a table.
     *
     * @param  \Illuminate\Database\Connection  $connection
     * @param  string  $tableName
     * @return string
     */
    protected function getTableSchema(Connection $connection, string $tableName): string
    {
        $output = "\n-- Table: {$tableName}\n";
        $output .= "CREATE TABLE {$tableName} (\n";

        // Get columns
        // In Informix, coltype >= 256 means NOT NULL (256 is added to the base type)
        $columns = $connection->select("
            SELECT c.colname, c.coltype, c.collength, c.colno
            FROM syscolumns c
            JOIN systables t ON c.tabid = t.tabid
            WHERE t.tabname = ?
            ORDER BY c.colno
        ", [$tableName]);

        $columnDefs = [];
        foreach ($columns as $col) {
            if (is_object($col)) {
                $colName = $col->colname ?? $col->COLNAME;
                $colType = $col->coltype ?? $col->COLTYPE;
                $colLength = $col->collength ?? $col->COLLENGTH;
            } else {
                $colName = $col['colname'] ?? $col['COLNAME'];
                $colType = $col['coltype'] ?? $col['COLTYPE'];
                $colLength = $col['collength'] ?? $col['COLLENGTH'];
            }

            // In Informix, if coltype >= 256, the column is NOT NULL
            $isNotNull = ((int)$colType >= 256);
            $typeName = $this->mapInformixType((int)$colType, (int)$colLength);
            $notNullStr = $isNotNull ? ' NOT NULL' : '';
            
            $columnDefs[] = "    {$colName} {$typeName}{$notNullStr}";
        }

        $output .= implode(",\n", $columnDefs);
        $output .= "\n);\n";

        // Get indexes
        $output .= $this->getTableIndexes($connection, $tableName);

        // Get foreign keys
        $output .= $this->getTableForeignKeys($connection, $tableName);

        return $output;
    }

    /**
     * Map Informix type codes to SQL type names.
     *
     * @param  int  $typeCode
     * @param  int  $length
     * @return string
     */
    protected function mapInformixType(int $typeCode, int $length): string
    {
        // Remove the NOT NULL flag (256) if present
        $baseType = $typeCode % 256;

        $types = [
            0 => 'CHAR(' . $length . ')',
            1 => 'SMALLINT',
            2 => 'INTEGER',
            3 => 'FLOAT',
            4 => 'SMALLFLOAT',
            5 => 'DECIMAL',
            6 => 'SERIAL',
            7 => 'DATE',
            8 => 'MONEY',
            9 => 'NULL',
            10 => 'DATETIME YEAR TO SECOND',
            11 => 'BYTE',
            12 => 'TEXT',
            13 => 'VARCHAR(' . $length . ')',
            14 => 'INTERVAL',
            15 => 'NCHAR(' . $length . ')',
            16 => 'NVARCHAR(' . $length . ')',
            17 => 'INT8',
            18 => 'SERIAL8',
            19 => 'SET',
            20 => 'MULTISET',
            21 => 'LIST',
            22 => 'ROW',
            23 => 'COLLECTION',
            40 => 'LVARCHAR(' . $length . ')',
            41 => 'BOOLEAN',
            43 => 'LVARCHAR(' . $length . ')',
            45 => 'BOOLEAN',
            52 => 'BIGINT',
            53 => 'BIGSERIAL',
        ];

        return $types[$baseType] ?? "UNKNOWN({$typeCode})";
    }

    /**
     * Get index definitions for a table.
     *
     * @param  \Illuminate\Database\Connection  $connection
     * @param  string  $tableName
     * @return string
     */
    protected function getTableIndexes(Connection $connection, string $tableName): string
    {
        $output = '';

        $indexes = $connection->select("
            SELECT i.idxname, i.idxtype, i.part1, i.part2, i.part3, i.part4,
                   i.part5, i.part6, i.part7, i.part8
            FROM sysindexes i
            JOIN systables t ON i.tabid = t.tabid
            WHERE t.tabname = ?
        ", [$tableName]);

        foreach ($indexes as $idx) {
            if (is_object($idx)) {
                $idxName = $idx->idxname ?? $idx->IDXNAME;
                $idxType = $idx->idxtype ?? $idx->IDXTYPE;
            } else {
                $idxName = $idx['idxname'] ?? $idx['IDXNAME'];
                $idxType = $idx['idxtype'] ?? $idx['IDXTYPE'];
            }

            // Get column names for the index
            $parts = [];
            for ($i = 1; $i <= 8; $i++) {
                $partKey = "part{$i}";
                $partKeyUpper = "PART{$i}";
                $partNum = is_object($idx) ? ($idx->$partKey ?? $idx->$partKeyUpper ?? 0) : ($idx[$partKey] ?? $idx[$partKeyUpper] ?? 0);
                if ($partNum > 0) {
                    // Get column name
                    $colResult = $connection->select("
                        SELECT c.colname 
                        FROM syscolumns c 
                        JOIN systables t ON c.tabid = t.tabid 
                        WHERE t.tabname = ? AND c.colno = ?
                    ", [$tableName, $partNum]);
                    
                    if (!empty($colResult)) {
                        $colName = is_object($colResult[0]) 
                            ? ($colResult[0]->colname ?? $colResult[0]->COLNAME)
                            : ($colResult[0]['colname'] ?? $colResult[0]['COLNAME']);
                        $parts[] = $colName;
                    }
                }
            }

            if (!empty($parts)) {
                $unique = ($idxType === 'U') ? 'UNIQUE ' : '';
                $columns = implode(', ', $parts);
                $output .= "CREATE {$unique}INDEX {$idxName} ON {$tableName} ({$columns});\n";
            }
        }

        return $output;
    }

    /**
     * Get foreign key definitions for a table.
     *
     * @param  \Illuminate\Database\Connection  $connection
     * @param  string  $tableName
     * @return string
     */
    protected function getTableForeignKeys(Connection $connection, string $tableName): string
    {
        $output = '';

        try {
            $foreignKeys = $connection->select("
                SELECT rc.constrname, 
                       pt.tabname AS parent_table,
                       ct.tabname AS child_table,
                       rc.delrule
                FROM sysconstraints rc
                JOIN systables ct ON rc.tabid = ct.tabid
                JOIN sysreferences sr ON rc.constrid = sr.constrid
                JOIN sysconstraints pc ON sr.primary = pc.constrid
                JOIN systables pt ON pc.tabid = pt.tabid
                WHERE ct.tabname = ?
                  AND rc.constrtype = 'R'
            ", [$tableName]);

            foreach ($foreignKeys as $fk) {
                if (is_object($fk)) {
                    $fkName = $fk->constrname ?? $fk->CONSTRNAME;
                    $parentTable = $fk->parent_table ?? $fk->PARENT_TABLE;
                    $delRule = $fk->delrule ?? $fk->DELRULE ?? 'R';
                } else {
                    $fkName = $fk['constrname'] ?? $fk['CONSTRNAME'];
                    $parentTable = $fk['parent_table'] ?? $fk['PARENT_TABLE'];
                    $delRule = $fk['delrule'] ?? $fk['DELRULE'] ?? 'R';
                }

                $onDelete = match ($delRule) {
                    'C' => 'CASCADE',
                    'N' => 'SET NULL',
                    'D' => 'SET DEFAULT',
                    default => 'RESTRICT',
                };

                $output .= "-- Foreign Key: {$fkName} references {$parentTable} ON DELETE {$onDelete}\n";
            }
        } catch (\Exception $e) {
            // Ignore errors when getting foreign keys
        }

        return $output;
    }

    /**
     * Append the migration data to the schema dump.
     *
     * @param  \Illuminate\Database\Connection  $connection
     * @param  string  $path
     * @return void
     */
    protected function appendMigrationData(Connection $connection, string $path): void
    {
        $migrations = $connection->select(
            "SELECT * FROM {$this->getMigrationTable()} ORDER BY batch, migration"
        );

        if (empty($migrations)) {
            return;
        }

        $output = "\n-- Migration data\n";
        
        foreach ($migrations as $migration) {
            if (is_object($migration)) {
                $migrationName = $migration->migration ?? $migration->MIGRATION;
                $batch = $migration->batch ?? $migration->BATCH;
            } else {
                $migrationName = $migration['migration'] ?? $migration['MIGRATION'];
                $batch = $migration['batch'] ?? $migration['BATCH'];
            }
            
            $output .= "INSERT INTO {$this->getMigrationTable()} (migration, batch) VALUES ('{$migrationName}', {$batch});\n";
        }

        $this->files->append($path, $output);
    }

    /**
     * Load the given schema file into the database.
     *
     * @param  string  $path
     * @return void
     */
    public function load($path)
    {
        $sql = $this->files->get($path);
        
        // Split by semicolons and execute each statement
        $statements = array_filter(
            array_map('trim', explode(';', $sql)),
            fn($stmt) => !empty($stmt) && !str_starts_with(trim($stmt), '--')
        );

        foreach ($statements as $statement) {
            if (!empty(trim($statement))) {
                $this->connection->unprepared($statement);
            }
        }
    }
}
