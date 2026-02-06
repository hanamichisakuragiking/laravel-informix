<?php

namespace Hanamichisakuragiking\LaravelInformix\Query\Grammars;

use Illuminate\Database\Query\Builder;
use Illuminate\Database\Query\Grammars\Grammar;

class InformixGrammar extends Grammar
{
    /**
     * The components that make up a select clause.
     *
     * @var string[]
     */
    protected $selectComponents = [
        'aggregate',
        'columns',
        'from',
        'indexHint',
        'joins',
        'wheres',
        'groups',
        'havings',
        'orders',
        'unions',
        'lock',
    ];

    /**
     * Compile a select query into SQL.
     * Informix uses FIRST/SKIP instead of LIMIT/OFFSET.
     *
     * @param  Builder  $query
     * @return string
     */
    public function compileSelect(Builder $query): string
    {
        if (is_null($query->columns)) {
            $query->columns = ['*'];
        }

        $sql = trim($this->concatenate($this->compileComponents($query)));

        return $sql;
    }

    /**
     * Compile the "select *" portion of the query.
     * Adds FIRST and SKIP for pagination.
     *
     * @param  Builder  $query
     * @param  array  $columns
     * @return string|null
     */
    protected function compileColumns(Builder $query, $columns): ?string
    {
        // If the query is actually performing an aggregating select, we will let that
        // compiler handle the building of the select clauses
        if (!is_null($query->aggregate)) {
            return null;
        }

        $select = 'SELECT';

        // Informix pagination: SELECT FIRST n SKIP m
        if ($query->offset > 0) {
            $select .= ' SKIP ' . (int) $query->offset;
        }

        if ($query->limit > 0) {
            $select .= ' FIRST ' . (int) $query->limit;
        }

        if ($query->distinct) {
            $select .= ' DISTINCT';
        }

        return $select . ' ' . $this->columnize($columns);
    }

    /**
     * Compile the "limit" portions of the query.
     * Informix handles this in compileColumns with FIRST.
     *
     * @param  Builder  $query
     * @param  int  $limit
     * @return string
     */
    protected function compileLimit(Builder $query, $limit): string
    {
        return '';
    }

    /**
     * Compile the "offset" portions of the query.
     * Informix handles this in compileColumns with SKIP.
     *
     * @param  Builder  $query
     * @param  int  $offset
     * @return string
     */
    protected function compileOffset(Builder $query, $offset): string
    {
        return '';
    }

    /**
     * Compile the lock into SQL.
     *
     * @param  Builder  $query
     * @param  bool|string  $value
     * @return string
     */
    protected function compileLock(Builder $query, $value): string
    {
        if (is_string($value)) {
            return $value;
        }

        return $value ? ' FOR UPDATE' : ' FOR READ ONLY';
    }

    /**
     * Compile an insert statement into SQL.
     *
     * @param  Builder  $query
     * @param  array  $values
     * @return string
     */
    public function compileInsert(Builder $query, array $values): string
    {
        $table = $this->wrapTable($query->from);

        if (empty($values)) {
            return "INSERT INTO {$table} DEFAULT VALUES";
        }

        if (!is_array(reset($values))) {
            $values = [$values];
        }

        // Informix doesn't support multi-row INSERT in older versions
        // For compatibility, we only insert the first row
        $record = reset($values);
        $columns = $this->columnize(array_keys($record));
        $parameters = $this->parameterize($record);

        return "INSERT INTO {$table} ({$columns}) VALUES ({$parameters})";
    }

    /**
     * Compile an exists statement into SQL.
     *
     * @param  Builder  $query
     * @return string
     */
    public function compileExists(Builder $query): string
    {
        $existsQuery = clone $query;
        $existsQuery->columns = [];

        return $this->compileSelect($existsQuery->selectRaw('1'));
    }

    /**
     * Wrap a single string in keyword identifiers.
     * Informix doesn't use backticks or quotes for identifiers by default.
     *
     * @param  string  $value
     * @return string
     */
    protected function wrapValue($value): string
    {
        if ($value === '*') {
            return $value;
        }

        // Informix identifiers are case-insensitive and don't need quoting
        // unless they contain special characters
        return str_replace('"', '', $value);
    }

    /**
     * Get the format for database stored dates.
     *
     * @return string
     */
    public function getDateFormat(): string
    {
        return 'Y-m-d H:i:s';
    }

    /**
     * Compile a "where null" clause.
     *
     * @param  Builder  $query
     * @param  array  $where
     * @return string
     */
    protected function whereNull(Builder $query, $where): string
    {
        return $this->wrap($where['column']) . ' IS NULL';
    }

    /**
     * Compile a "where not null" clause.
     *
     * @param  Builder  $query
     * @param  array  $where
     * @return string
     */
    protected function whereNotNull(Builder $query, $where): string
    {
        return $this->wrap($where['column']) . ' IS NOT NULL';
    }

    /**
     * Compile a truncate table statement into SQL.
     *
     * @param  Builder  $query
     * @return array
     */
    public function compileTruncate(Builder $query): array
    {
        // Informix uses TRUNCATE TABLE
        return ['TRUNCATE TABLE ' . $this->wrapTable($query->from) => []];
    }
}
