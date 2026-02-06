<?php

namespace Hanamichisakuragiking\LaravelInformix\Query\Processors;

use Illuminate\Database\Query\Builder;
use Illuminate\Database\Query\Processors\Processor;

class InformixProcessor extends Processor
{
    /**
     * Process an "insert get ID" query.
     *
     * @param  Builder  $query
     * @param  string  $sql
     * @param  array  $values
     * @param  string|null  $sequence
     * @return int
     */
    public function processInsertGetId(Builder $query, $sql, $values, $sequence = null): int
    {
        $connection = $query->getConnection();
        
        // Extract table name from query
        $table = $query->from;

        // Perform the insert
        $connection->insert($sql, $values);

        // PDO_INFORMIX doesn't support lastInsertId properly
        // Workaround: Query for MAX(id) from the table
        // This works because SERIAL values are always increasing
        $result = $connection->selectOne(
            "SELECT MAX(id) AS last_id FROM {$table}"
        );

        return (int) ($result->last_id ?? 0);
    }

    /**
     * Process the results of a column listing query.
     *
     * @param  array  $results
     * @return array
     */
    public function processColumnListing($results): array
    {
        return array_map(function ($result) {
            return ((object) $result)->colname ?? $result->name ?? $result;
        }, $results);
    }
}
