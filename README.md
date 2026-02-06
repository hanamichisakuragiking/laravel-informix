# Laravel Informix

A Laravel 12+ database driver for IBM Informix.

## Requirements

- PHP 8.2+
- Laravel 11.x or 12.x
- PDO_INFORMIX extension
- IBM Informix Client SDK (CSDK)

## Installation

```bash
composer require hanamichisakuragiking/laravel-informix
```

The service provider will be auto-discovered by Laravel.

## Configuration

Add the Informix connection to your `config/database.php`:

```php
'connections' => [
    // ... other connections

    'informix' => [
        'driver' => 'informix',
        'host' => env('INFORMIX_HOST', 'localhost'),
        'service' => env('INFORMIX_SERVICE', '9088'),
        'database' => env('INFORMIX_DATABASE', 'forge'),
        'server' => env('INFORMIX_SERVER', 'informix'),
        'username' => env('INFORMIX_USERNAME', 'informix'),
        'password' => env('INFORMIX_PASSWORD', ''),
        'protocol' => env('INFORMIX_PROTOCOL', 'onsoctcp'),
        'db_locale' => env('INFORMIX_DB_LOCALE', 'en_US.utf8'),
        'client_locale' => env('INFORMIX_CLIENT_LOCALE', 'en_US.utf8'),
        'prefix' => '',
        'prefix_indexes' => true,
    ],
],
```

Add to your `.env`:

```env
INFORMIX_HOST=10.0.0.1
INFORMIX_SERVICE=9088
INFORMIX_DATABASE=mydb
INFORMIX_SERVER=informix_server
INFORMIX_USERNAME=informix
INFORMIX_PASSWORD=secret
INFORMIX_DB_LOCALE=en_US.utf8
INFORMIX_CLIENT_LOCALE=en_US.utf8
```

## Usage

### Query Builder

```php
// Using the informix connection
$users = DB::connection('informix')->table('users')->get();

// With pagination (uses FIRST/SKIP)
$users = DB::connection('informix')
    ->table('users')
    ->skip(10)
    ->take(20)
    ->get();

// Insert and get ID
$id = DB::connection('informix')->table('users')->insertGetId([
    'name' => 'John Doe',
    'email' => 'john@example.com',
]);
```

### Eloquent Models

```php
<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class User extends Model
{
    protected $connection = 'informix';
    protected $table = 'users';
}
```

### Migrations

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    protected $connection = 'informix';

    public function up(): void
    {
        Schema::connection('informix')->create('users', function (Blueprint $table) {
            $table->id();  // Creates SERIAL column
            $table->string('name', 100);
            $table->string('email', 255)->unique();
            $table->timestamp('created_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::connection('informix')->dropIfExists('users');
    }
};
```

## Informix SQL Differences

This driver handles the following Informix-specific syntax:

| Feature | MySQL/PostgreSQL | Informix |
|---------|------------------|----------|
| Pagination | `LIMIT n OFFSET m` | `SELECT FIRST n SKIP m` |
| Auto-increment | `AUTO_INCREMENT` / `SERIAL` | `SERIAL` / `SERIAL8` |
| Boolean | `BOOLEAN` / `TINYINT(1)` | `CHAR(1)` with 't'/'f' |
| Identity | `LAST_INSERT_ID()` | `DBINFO('sqlca.sqlerrd1')` |

## Known Limitations

1. **Multi-row INSERT handled automatically** - Informix does not support `INSERT INTO t VALUES (...), (...)` syntax. The driver automatically splits multi-row inserts into individual statements wrapped in a transaction.

2. **No DROP IF EXISTS** - Informix doesn't support `DROP TABLE IF EXISTS`. The driver checks `systables` first.

3. **No RETURNING clause** - After INSERT, use `DBINFO('sqlca.sqlerrd1')` to get the last SERIAL value.

4. **Limited ALTER TABLE** - Modifying columns is restricted in Informix.

## PDO_INFORMIX Workarounds

This driver includes workarounds for several PDO_INFORMIX bugs and limitations:

### 1. prepare() Segfault Bug

PDO_INFORMIX's `prepare()` method causes segmentation faults in many scenarios. This driver uses `query()` with manual parameter binding instead.

### 2. ATTR_EMULATE_PREPARES Not Supported

Unlike PostgreSQL, MySQL, and other PDO drivers, PDO_INFORMIX does **not** support `PDO::ATTR_EMULATE_PREPARES`. Attempting to set it throws:

```
SQLSTATE[IM001]: Driver does not support this function
```

### 3. Bcrypt Password Hash Corruption (Fixed in v1.1)

**Problem**: When storing bcrypt password hashes like `$2y$10$abc...`, the data was being corrupted:

| Original | Stored |
|----------|--------|
| `$2y$10$7K0U4hvZ...` (60 chars) | `yK0U4hvZ...` (53 chars) |

**Root Cause**: The manual parameter binding used `preg_replace('/\?/', $value, $query, 1)` to substitute `?` placeholders. PHP's `preg_replace` interprets `$` followed by digits in the replacement string as backreferences:

- `$2` → backreference to capture group 2 (empty)
- `$10` → backreference to capture group 10 (empty)

This caused `$2y$10$` to be stripped from bcrypt hashes.

**Solution**: Changed from `preg_replace()` to `strpos()`/`substr()` for literal string replacement:

```php
// Before (broken)
$query = preg_replace('/\?/', $value, $query, 1);

// After (fixed)
$pos = strpos($query, '?');
if ($pos !== false) {
    $query = substr($query, 0, $pos) . $value . substr($query, $pos + 1);
}
```

**Why Other Databases Don't Have This Issue**:

| Database | Native Prepare | ATTR_EMULATE_PREPARES | Manual Binding Needed |
|----------|---------------|----------------------|----------------------|
| PostgreSQL | ✅ | ✅ | No |
| MySQL | ✅ | ✅ | No |
| SQLite | ✅ | ✅ | No |
| SQL Server | ✅ | ✅ | No |
| **Informix** | ⚠️ Buggy | ❌ | **Yes** |

### 4. Single Quote Escaping (Fixed in v1.2)

**Problem**: PDO::quote() uses backslash escaping (`\'`) for single quotes, but Informix requires SQL standard escaping (`''`):

```sql
-- PDO::quote() generates (FAILS):
INSERT INTO table (message) VALUES ('Test with \' quote')
-- ERROR -11060: A field with an unbalanced quote was received

-- Informix requires (WORKS):
INSERT INTO table (message) VALUES ('Test with '' quote')
```

**Solution**: Custom `quoteString()` method that uses SQL standard escaping:

```php
protected function quoteString($value)
{
    if (is_null($value)) {
        return 'NULL';
    }
    // Use SQL standard '' escaping instead of \'
    return "'" . str_replace("'", "''", $value) . "'";
}
```

This allows storing text with apostrophes like "It's working", "O'Brien", "Can't stop", etc.

### 5. ATTR_SERVER_VERSION Not Supported

PDO_INFORMIX doesn't support `getAttribute(PDO::ATTR_SERVER_VERSION)`. The driver returns `'Informix'` as a fallback.

## Comparison with Other PDO Drivers

| Feature | PostgreSQL | MySQL | Informix |
|---------|-----------|-------|----------|
| Prepared statements | Native | Native | Manual workaround |
| Emulated prepares | Supported | Supported | Not supported |
| Parameter binding | Driver-level | Driver-level | String interpolation |
| Special char handling | Automatic | Automatic | Manual escaping |
| Driver maintenance | Active | Active | Legacy (~2015) |

## Laravel Feature Compatibility

Tested with Laravel 12.x and Informix IDS 14.10:

### ✅ Fully Supported

| Feature | Status | Notes |
|---------|--------|-------|
| Basic CRUD | ✅ | SELECT, INSERT, UPDATE, DELETE |
| Aggregate functions | ✅ | COUNT, MAX, MIN, AVG, SUM |
| WHERE clauses | ✅ | All comparison operators |
| LIKE queries | ✅ | Pattern matching works |
| ORDER BY | ✅ | Multiple columns supported |
| GROUP BY | ✅ | With `havingRaw()` |
| JOIN queries | ✅ | INNER, LEFT, RIGHT joins |
| UNION queries | ✅ | |
| Subqueries | ✅ | whereIn with subquery |
| Transactions | ✅ | commit, rollback |
| Eloquent Models | ✅ | Full ORM support |
| Relationships | ✅ | hasMany, belongsTo, etc. |
| Eager Loading | ✅ | `with()` works |
| Pagination | ✅ | Uses FIRST/SKIP syntax |
| Cursor Pagination | ✅ | Memory-efficient |
| firstOrCreate | ✅ | |
| updateOrCreate | ✅ | |
| Date functions | ✅ | whereYear, whereMonth, etc. |
| Raw queries | ✅ | DB::select(), DB::statement() |

### ⚠️ Use With Care

| Feature | Issue | Workaround |
|---------|-------|------------|
| `having('alias', '>', n)` | Column alias not found | Use `havingRaw('COUNT(*) > n')` |
| `increment()` on SERIAL | Error -232 | Don't increment primary keys |
| Multi-row INSERT | Slower | Driver splits into individual inserts |

### ❌ Not Supported

| Feature | Reason |
|---------|--------|
| `upsert()` | Informix lacks ON CONFLICT/ON DUPLICATE KEY |
| JSON columns | Informix has limited JSON support |
| `whereFullText()` | Different full-text syntax |
| `insertOrIgnore()` | Not available in Informix |

## Testing with Special Characters

When testing this driver, ensure you test with data containing:

- Dollar signs: `$variable`, `$2y$10$...` (bcrypt)
- Single quotes: `O'Brien`
- Double quotes: `Say "hello"`
- Backslashes: `C:\path\to\file`
- Null bytes: Binary data

## Changelog

### v1.2.0
- Fixed single quote escaping (use SQL standard `''` instead of `\'`)
- Added Laravel feature compatibility documentation

### v1.1.0
- Fixed bcrypt password hash corruption (`$2y$10$` being stripped)
- Changed from `preg_replace` to `strpos/substr` for parameter binding

### v1.0.0
- Initial release with PDO_INFORMIX workarounds
- Support for Laravel 11.x and 12.x

## License

MIT License
