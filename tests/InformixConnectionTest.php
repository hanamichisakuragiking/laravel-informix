<?php

namespace Hanamichisakuragiking\LaravelInformix\Tests;

use DateTimeImmutable;
use Hanamichisakuragiking\LaravelInformix\InformixConnection;
use Hanamichisakuragiking\LaravelInformix\InformixConnector;
use Hanamichisakuragiking\LaravelInformix\InformixServiceProvider;
use Hanamichisakuragiking\LaravelInformix\Query\Grammars\InformixGrammar as QueryGrammar;
use Hanamichisakuragiking\LaravelInformix\Query\Processors\InformixProcessor;
use Hanamichisakuragiking\LaravelInformix\Schema\Builder as SchemaBuilder;
use Hanamichisakuragiking\LaravelInformix\Schema\Grammars\InformixGrammar as SchemaGrammar;
use Hanamichisakuragiking\LaravelInformix\Schema\InformixSchemaState;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

class InformixConnectionTest extends TestCase
{
    public function test_it_prepares_informix_bindings(): void
    {
        $connection = new TestInformixConnection();

        $bindings = $connection->prepareBindings([
            new DateTimeImmutable('2026-10-20 14:30:45'),
            true,
            false,
            null,
        ]);

        $this->assertSame(['2026-10-20 14:30:45', 't', 'f', null], $bindings);
    }

    public function test_it_interpolates_values_without_corrupting_special_characters(): void
    {
        $connection = new TestInformixConnection();

        $sql = $connection->interpolate(
            'INSERT INTO users (password, name, active, score, deleted_at) VALUES (?, ?, ?, ?, ?)',
            ['$2y$10$abc', "O'Brien", true, 12.5, null],
        );

        $this->assertSame(
            'INSERT INTO users (password, name, active, score, deleted_at) VALUES (\'$2y$10$abc\', \'O\'\'Brien\', \'t\', 12.5, NULL)',
            $sql,
        );
    }

    public function test_select_accepts_laravel_13_fetch_arguments(): void
    {
        $method = new ReflectionMethod(InformixConnection::class, 'select');

        $this->assertCount(4, $method->getParameters());
        $this->assertTrue($method->getParameters()[3]->isOptional());
        $this->assertSame([], $method->getParameters()[3]->getDefaultValue());
    }

    public function test_all_package_classes_are_compatible_with_the_installed_laravel_version(): void
    {
        foreach ([
            InformixConnection::class,
            InformixConnector::class,
            InformixServiceProvider::class,
            QueryGrammar::class,
            InformixProcessor::class,
            SchemaBuilder::class,
            SchemaGrammar::class,
            InformixSchemaState::class,
        ] as $class) {
            $this->assertTrue(class_exists($class), "Failed loading {$class}");
        }
    }
}

class TestInformixConnection extends InformixConnection
{
    public function __construct()
    {
        parent::__construct(static fn () => throw new \RuntimeException('No database access expected.'));
    }

    public function interpolate(string $query, array $bindings): string
    {
        return $this->bindValuesIntoQuery($query, $bindings);
    }
}
