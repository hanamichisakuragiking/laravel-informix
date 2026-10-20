<?php

namespace Hanamichisakuragiking\LaravelInformix\Tests;

use Hanamichisakuragiking\LaravelInformix\InformixConnection;
use PHPUnit\Framework\TestCase;

class InformixGrammarTest extends TestCase
{
    private InformixConnection $connection;

    protected function setUp(): void
    {
        parent::setUp();

        $this->connection = new InformixConnection(
            static fn () => throw new \RuntimeException('No database access expected.'),
        );
    }

    public function test_it_compiles_informix_pagination(): void
    {
        $sql = $this->connection->table('users')
            ->orderBy('id')
            ->offset(10)
            ->limit(20)
            ->toSql();

        $this->assertSame('SELECT SKIP 10 FIRST 20 * from users order by id asc', $sql);
    }

    public function test_it_compiles_boolean_bindings_as_informix_characters(): void
    {
        $query = $this->connection->table('users')->where('active', true);

        $this->assertSame('SELECT * from users where active = ?', $query->toSql());
        $this->assertSame(['t'], $this->connection->prepareBindings($query->getBindings()));
    }

    public function test_it_compiles_single_row_insert_syntax_for_batch_values(): void
    {
        $query = $this->connection->table('users');
        $grammar = $this->connection->getQueryGrammar();

        $sql = $grammar->compileInsert($query, [
            ['email' => 'one@example.com', 'name' => 'One'],
            ['email' => 'two@example.com', 'name' => 'Two'],
        ]);

        $this->assertSame('INSERT INTO users (email, name) VALUES (?, ?)', $sql);
    }
}
