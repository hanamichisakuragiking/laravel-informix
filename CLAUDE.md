# Laravel Informix Connector - Development Guide

## Project Overview

This package provides Laravel 12 database driver support for IBM Informix IDS databases using PDO_INFORMIX.

**Repository:** https://github.com/hanamichisakuragiking/laravel-informix

## Architecture

```
src/
├── InformixServiceProvider.php    # Registers 'informix' driver with Laravel
├── InformixConnector.php          # Builds PDO connection and DSN
├── InformixConnection.php         # Core connection class with query methods
├── Query/
│   ├── Grammars/
│   │   └── InformixGrammar.php    # SELECT FIRST/SKIP syntax for pagination
│   └── Processors/
│       └── InformixProcessor.php  # insertGetId with MAX(id) workaround
└── Schema/
    ├── Builder.php                # hasTable, dropIfExists, getTables, getColumns, getIndexes, getForeignKeys
    └── Grammars/
        └── InformixGrammar.php    # CREATE TABLE, column types, migrations, compileColumns, compileIndexes, compileForeignKeys
```

## Critical PDO_INFORMIX Bugs & Workarounds

### 1. prepare() Segmentation Fault (PHP 8.3)

**Problem:** `PDO::prepare()` causes segfault with PDO_INFORMIX 1.3.6 on PHP 8.3.

**Workaround:** Use `PDO::query()` for SELECT and `PDO::exec()` for INSERT/UPDATE/DELETE with manual parameter binding.

```php
// In InformixConnection.php
public function select($query, $bindings = [], $useReadPdo = true): array
{
    // Manual binding instead of prepare() due to PDO_INFORMIX bug
    $preparedQuery = $this->bindValuesIntoQuery($query, $this->prepareBindings($bindings));
    $statement = $pdo->query($preparedQuery);
    return $statement->fetchAll(PDO::FETCH_OBJ);
}
```

### 2. lastInsertId() Returns 0

**Problem:** `PDO::lastInsertId()` always returns 0 for PDO_INFORMIX.

**Workaround:** Query `MAX(id)` from the table after insert.

```php
// In InformixProcessor.php
public function processInsertGetId(Builder $query, $sql, $values, $sequence = null): int
{
    $connection->insert($sql, $values);
    $result = $connection->selectOne("SELECT MAX(id) AS last_id FROM {$table}");
    return (int) ($result->last_id ?? 0);
}
```

### 3. ATTR_AUTOCOMMIT Ignored in Constructor

**Problem:** PDO_INFORMIX ignores `PDO::ATTR_AUTOCOMMIT` when passed in constructor options.

**Workaround:** Set after connection is established.

```php
// In InformixServiceProvider.php
$pdo = $connector->connect($config);
$pdo->setAttribute(PDO::ATTR_AUTOCOMMIT, true);  // Must be after connect
```

### 4. Column Names Returned in UPPERCASE

**Problem:** PDO_INFORMIX returns column names in UPPERCASE, breaking Eloquent.

**Workaround:** Set `PDO::ATTR_CASE` to `PDO::CASE_LOWER`.

```php
$pdo->setAttribute(PDO::ATTR_CASE, PDO::CASE_LOWER);
```

## Informix-Specific SQL Syntax

### Pagination (FIRST/SKIP instead of LIMIT/OFFSET)

```sql
-- Informix syntax
SELECT FIRST 10 SKIP 20 * FROM users

-- MySQL/PostgreSQL syntax (not supported)
SELECT * FROM users LIMIT 10 OFFSET 20
```

### Serial (Auto-increment) Columns

```sql
-- Informix uses SERIAL instead of AUTO_INCREMENT
CREATE TABLE users (
    id SERIAL PRIMARY KEY,
    name VARCHAR(255)
)
```

### Date/Time Functions

```sql
-- Current timestamp
SELECT CURRENT FROM systables WHERE tabid = 1

-- Date formatting
SELECT TO_CHAR(created_at, '%Y-%m-%d') FROM users
```

## Laravel 12 Compatibility Issues Fixed

### Method Signature Changes

Laravel 12 changed several method signatures that required updates:

```php
// Schema Grammar - compileTableExists now takes 2 parameters
public function compileTableExists($schema, $table): string

// Schema Builder - getTables now takes optional $schema
public function getTables($schema = null): array

// Schema Builder - getViews now takes optional $schema  
public function getViews($schema = null): array
```

### getTables() Return Format

Laravel's `ShowCommand` expects arrays with specific keys:

```php
return [
    'name' => $name,
    'schema' => null,
    'schema_qualified_name' => $name,
    'size' => null,
    'comment' => null,
    'collation' => null,
    'engine' => null,
];
```

## Environment Setup

### Required Components

- **Informix CSDK 4.50.FC8** (Client SDK)
- **PDO_INFORMIX 1.3.6** (PHP extension)
- **PHP 8.3** with php-devel for compilation
- **Informix IDS 14.10** (Server)

### Environment Variables

```bash
export INFORMIXDIR=/opt/informix-csdk
export INFORMIXSERVER=your_server_name
export INFORMIXSQLHOSTS=/opt/informix-csdk/etc/sqlhosts
export LD_LIBRARY_PATH=$INFORMIXDIR/lib:$INFORMIXDIR/lib/esql:$INFORMIXDIR/lib/cli
export PATH=$INFORMIXDIR/bin:$PATH
```

### sqlhosts Configuration

```
# /opt/informix-csdk/etc/sqlhosts
server_name  onsoctcp  hostname  port_or_service
```

## Database Configuration

### config/database.php

```php
'informix' => [
    'driver' => 'informix',
    'host' => env('DB_HOST', '192.168.1.1'),
    'service' => env('DB_PORT', '8888'),
    'database' => env('DB_DATABASE', 'laravel_app1'),
    'username' => env('DB_USERNAME', 'informix'),
    'password' => env('DB_PASSWORD', ''),
    'server' => env('DB_SERVER', 'erdb02'),
    'db_locale' => env('DB_LOCALE', 'zh_TW.utf8'),
    'client_locale' => env('CLIENT_LOCALE', 'zh_TW.utf8'),
    'protocol' => env('INFORMIX_PROTOCOL', 'onsoctcp'),
    'prefix' => '',
    'prefix_indexes' => true,
],
```

### Locale Configuration

**CRITICAL:** DB_LOCALE and CLIENT_LOCALE must match the database's locale setting exactly, or you'll get error -23197 (locale mismatch).

Check database locale:
```sql
SELECT name, dbs_collate FROM sysmaster:sysdatabases WHERE name = 'your_db'
```

## Testing Commands

```bash
# Test connection
php artisan db:show --database=informix

# View table structure
php artisan db:table users --database=informix

# Run migrations
php artisan migrate --database=informix

# Fresh migration
php artisan migrate:fresh --database=informix

# Migration status
php artisan migrate:status --database=informix

# Rollback/Reset
php artisan migrate:rollback --database=informix
php artisan migrate:reset --database=informix
php artisan migrate:refresh --database=informix --seed

# Seeding
php artisan db:seed --database=informix

# Tinker testing
php artisan tinker
>>> DB::connection('informix')->select('SELECT FIRST 5 * FROM users')
>>> User::on('informix')->get()
>>> User::on('informix')->create(['name' => 'Test', 'email' => 'test@test.com', 'password' => bcrypt('pwd')])
>>> App\Models\Post::with('user')->first()  # Eager loading relationships
>>> App\Models\User::with('posts')->first()
```

## Verified Working Features (2026-01-26)

### ✅ Artisan Commands
- `php artisan db:show --database=informix` - Shows all tables and database info
- `php artisan db:table <table> --database=informix` - Shows columns, indexes, foreign keys
- `php artisan migrate --database=informix` - Run migrations
- `php artisan migrate:fresh --database=informix --seed` - Drop all tables and re-migrate
- `php artisan migrate:rollback --database=informix` - Rollback migrations
- `php artisan migrate:reset --database=informix` - Reset all migrations
- `php artisan migrate:refresh --database=informix --seed` - Refresh and seed
- `php artisan migrate:status --database=informix` - Show migration status
- `php artisan db:seed --database=informix` - Run seeders
- `php artisan tinker` - Interactive shell with Eloquent
- `php artisan make:model Post -mf` - Model/migration/factory generation
- `php artisan optimize` / `php artisan optimize:clear`
- `php artisan cache:clear` / `php artisan config:clear`

### ✅ Schema Builder Features
- `Schema::create()` - Create tables
- `Schema::drop()` / `Schema::dropIfExists()` - Drop tables
- `Schema::hasTable()` - Check table existence
- `Schema::getColumns()` - Get column information
- `Schema::getIndexes()` - Get index information
- `Schema::getForeignKeys()` - Get foreign key information
- `Schema::dropAllTables()` - Drop all tables (for migrate:fresh)

### ✅ Migration Column Types
- `$table->id()` - SERIAL8 PRIMARY KEY
- `$table->bigIncrements()` - SERIAL8 PRIMARY KEY
- `$table->increments()` - SERIAL PRIMARY KEY
- `$table->string()` - VARCHAR
- `$table->text()` - TEXT
- `$table->integer()` / `$table->bigInteger()` - INTEGER/INT8
- `$table->boolean()` - BOOLEAN
- `$table->datetime()` / `$table->timestamp()` - DATETIME YEAR TO SECOND
- `$table->date()` - DATE
- `$table->time()` - DATETIME HOUR TO SECOND
- `$table->foreignId()->constrained()->onDelete()` - Foreign keys

### ✅ Eloquent ORM
- Model CRUD (create, read, update, delete)
- Query Builder (where, orderBy, limit, offset)
- Pagination with SELECT FIRST/SKIP
- Eager loading relationships (with())
- BelongsTo / HasMany relationships
- insertGetId() with MAX(id) workaround

### ❌ Not Supported
- `php artisan schema:dump` - Requires getSchemaState() method (low priority)

## Common Errors & Solutions

### Error -23197: Locale Mismatch

```
[Informix]GL_USEGLS must specify all fields of lc_time 
to get consistent formatting for all time-related...
```

**Solution:** Ensure DB_LOCALE and CLIENT_LOCALE in config match exactly what the database was created with.

### Error -704: Primary Key Already Exists

**Solution:** Don't include PRIMARY KEY in CREATE TABLE when Laravel will add it via ALTER TABLE. Fixed by removing inline PK from `compileCreate()`.

### Error -206: Table Not Found

This often means the table was created but transaction wasn't committed.

**Solution:** Enable auto-commit: `$pdo->setAttribute(PDO::ATTR_AUTOCOMMIT, true)`

### Segmentation Fault on prepare()

**Solution:** Use the `query()` and `exec()` methods with manual binding instead of `prepare()`.

## Deployment with Ansible

The `laravel_dev` Ansible role deploys Laravel with this connector:

```yaml
# inventories/prod/host_vars/laravel-dev.yml
informix_host: 192.168.1.1
informix_port: 8888
informix_database: laravel_app1
informix_server: erdb02
db_locale: zh_TW.utf8
```

Key tasks:
1. Install PHP 8.3 from OS packages
2. Compile PDO_INFORMIX against PHP 8.3
3. Configure Informix CSDK environment
4. Deploy Laravel application
5. Configure Nginx + PHP-FPM

## Git Workflow

```bash
# Make changes
cd /home/jason/laravel-informix
# Edit files...

# Commit and push
git add -A
git commit -m "Description of changes"
git push

# Update on target server
cd /var/www/laravel-app1
COMPOSER_ALLOW_SUPERUSER=1 composer update hanamichisakuragiking/laravel-informix
```

## Files Reference

| File | Purpose |
|------|---------|
| `InformixServiceProvider.php` | Registers driver, sets PDO attributes post-connect |
| `InformixConnector.php` | Builds DSN string, PDO options |
| `InformixConnection.php` | Overrides select/statement/affectingStatement for PDO bugs |
| `Query/Grammars/InformixGrammar.php` | FIRST/SKIP pagination, query compilation |
| `Query/Processors/InformixProcessor.php` | processInsertGetId with MAX(id) workaround |
| `Schema/Builder.php` | getTables/getViews with proper format, hasTable, dropIfExists |
| `Schema/Grammars/InformixGrammar.php` | DDL compilation, column types, migrations |

## Future Improvements

- [ ] Implement getSchemaState() for schema:dump support
- [ ] Add support for Informix SEQUENCE instead of SERIAL
- [ ] Implement proper transaction support with savepoints
- [ ] Add support for Informix-specific data types (BYTE, CLOB, BLOB)
- [ ] Implement table size estimation for db:show
- [ ] Add comprehensive unit tests
- [ ] Support for Informix stored procedures
