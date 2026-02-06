# Informix Database Connection SOP

This document provides step-by-step instructions for connecting  Laravel application to an IBM Informix database using the [laravel-informix](https://github.com/hanamichisakuragiking/laravel-informix) package.

---

## Prerequisites

| Requirement | Version |
|-------------|---------|
| PHP | 8.2+ |
| Laravel | 11.x or 12.x |
| PDO_INFORMIX Extension | Required |
| IBM Informix Client SDK (CSDK) | Required |

> [!IMPORTANT]
> The `PDO_INFORMIX` PHP extension and IBM Informix Client SDK (CSDK) must be installed on your system before proceeding.

---

## Step 1: Install PDO_INFORMIX Extension (If Not Installed)

### 1.1 Install IBM Informix Client SDK

Download and install the IBM Informix Client SDK from IBM's website or use the package manager:

```bash
# Set environment variables (add to ~/.bashrc or ~/.profile)
export INFORMIXDIR=/opt/IBM/Informix_Client-SDK
export PATH=$INFORMIXDIR/bin:$PATH
export LD_LIBRARY_PATH=$INFORMIXDIR/lib:$INFORMIXDIR/lib/esql:$LD_LIBRARY_PATH
```

### 1.2 Install PDO_INFORMIX via PECL

```bash
pecl install PDO_INFORMIX
```

### 1.3 Enable the Extension

Add to your `php.ini`:

```ini
extension=pdo_informix.so
```

### 1.4 Verify Installation

```bash
php -m | grep -i informix
```

Expected output:
```
PDO_INFORMIX
```

---

## Step 2: Install the Laravel-Informix Package

Navigate to your project directory and install the package.

> [!NOTE]
> This package is hosted on GitHub and is not available on Packagist. You must add the repository manually before installing.

### 2.1 Add the GitHub Repository

```bash
cd /home/jason/book_store
composer config repositories.laravel-informix vcs https://github.com/hanamichisakuragiking/laravel-informix.git
```

### 2.2 Install the Package

```bash
composer require hanamichisakuragiking/laravel-informix:dev-master
```

Expected output:
```
./composer.json has been updated
Running composer update hanamichisakuragiking/laravel-informix
...
  - Installing hanamichisakuragiking/laravel-informix (dev-master): Extracting archive
...
   INFO  Discovering packages.
  hanamichisakuragiking/laravel-informix .................... DONE
```

The service provider will be auto-discovered by Laravel.

---

## Step 3: Configure the Database Connection

### 3.1 Update `config/database.php`

Add the Informix connection to the `connections` array:

```php
'connections' => [
    // ... other connections (sqlite, mysql, pgsql, etc.)

    'informix' => [
        'driver'        => 'informix',
        'host'          => env('INFORMIX_HOST', 'localhost'),
        'service'       => env('INFORMIX_SERVICE', '9088'),
        'database'      => env('INFORMIX_DATABASE', 'forge'),
        'server'        => env('INFORMIX_SERVER', 'informix'),
        'username'      => env('INFORMIX_USERNAME', 'informix'),
        'password'      => env('INFORMIX_PASSWORD', ''),
        'protocol'      => env('INFORMIX_PROTOCOL', 'onsoctcp'),
        'db_locale'     => env('INFORMIX_DB_LOCALE', 'en_US.utf8'),
        'client_locale' => env('INFORMIX_CLIENT_LOCALE', 'en_US.utf8'),
        'prefix'        => '',
        'prefix_indexes' => true,
    ],
],
```

### 3.2 Update `.env` File

Add the following environment variables:

```env
# Informix Database Configuration
INFORMIX_HOST=192.168.1.1
INFORMIX_SERVICE=8888
INFORMIX_DATABASE=your_database_name
INFORMIX_SERVER=your_informix_server_name
INFORMIX_USERNAME=your_username
INFORMIX_PASSWORD=your_password
INFORMIX_PROTOCOL=onsoctcp
INFORMIX_DB_LOCALE=en_US.utf8
INFORMIX_CLIENT_LOCALE=en_US.utf8
```

> [!NOTE]
> - **INFORMIX_HOST**: The IP address of your Informix server (`192.168.1.1`)
> - **INFORMIX_SERVICE**: The port number (`8888`)
> - **INFORMIX_SERVER**: The Informix server instance name (get this from your DBA)
> - **INFORMIX_DATABASE**: The database name (needs to be created on the server first)

---

## Step 4: Test the Connection

### Method 1: Using Artisan Tinker

```bash
cd /home/jason/book_store
php artisan tinker
```

In the Tinker shell:

```php
// Test basic connection
try {
    $pdo = DB::connection('informix')->getPdo();
    echo "✅ Connected successfully to Informix!\n";
    echo "Connection name: " . DB::connection('informix')->getName() . "\n";
} catch (\Exception $e) {
    echo "❌ Connection failed: " . $e->getMessage() . "\n";
}
```

### Method 2: Create a Test Route

Add to `routes/web.php`:

```php
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;

Route::get('/test-informix', function () {
    try {
        $pdo = DB::connection('informix')->getPdo();
        return response()->json([
            'status' => 'success',
            'message' => 'Connected to Informix successfully!',
            'connection' => DB::connection('informix')->getName(),
        ]);
    } catch (\Exception $e) {
        return response()->json([
            'status' => 'error',
            'message' => 'Connection failed: ' . $e->getMessage(),
        ], 500);
    }
});
```

Then test via browser or curl:

```bash
php artisan serve
# In another terminal:
curl http://127.0.0.1:8000/test-informix
```

### Method 3: Create a Test Command

Create an Artisan command for testing:

```bash
php artisan make:command TestInformixConnection
```

Edit `app/Console/Commands/TestInformixConnection.php`:

```php
<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class TestInformixConnection extends Command
{
    protected $signature = 'informix:test';
    protected $description = 'Test the Informix database connection';

    public function handle()
    {
        $this->info('Testing Informix connection...');
        $this->newLine();

        try {
            // Test 1: Basic connection
            $pdo = DB::connection('informix')->getPdo();
            $this->info('✅ PDO connection established');

            // Test 2: Run a simple query
            $result = DB::connection('informix')->select('SELECT CURRENT FROM systables WHERE tabid = 1');
            $this->info('✅ Query executed successfully');

            // Test 3: Get connection info
            $this->table(
                ['Property', 'Value'],
                [
                    ['Host', config('database.connections.informix.host')],
                    ['Service/Port', config('database.connections.informix.service')],
                    ['Database', config('database.connections.informix.database')],
                    ['Server', config('database.connections.informix.server')],
                    ['Status', 'Connected'],
                ]
            );

            $this->newLine();
            $this->info('🎉 All connection tests passed!');

            return Command::SUCCESS;

        } catch (\Exception $e) {
            $this->error('❌ Connection failed!');
            $this->newLine();
            $this->error('Error: ' . $e->getMessage());
            $this->newLine();
            $this->warn('Troubleshooting tips:');
            $this->line('  1. Verify the Informix server is running');
            $this->line('  2. Check firewall rules for port 8888');
            $this->line('  3. Verify credentials are correct');
            $this->line('  4. Ensure the database exists on the server');
            $this->line('  5. Confirm INFORMIX_SERVER name matches the server config');

            return Command::FAILURE;
        }
    }
}
```

Run the test:

```bash
php artisan informix:test
```

---

## Step 5: Create the Database (On Informix Server)

> [!CAUTION]
> The database must be created on the Informix server side. This requires DBA access.

Connect to your Informix server and run:

```sql
-- Create the database with appropriate logging
CREATE DATABASE book_store WITH LOG;
```

Or using dbaccess:

```bash
dbaccess - -
CREATE DATABASE book_store WITH LOG;
```

---

## Usage Examples

### Query Builder

```php
use Illuminate\Support\Facades\DB;

// Get all records
$books = DB::connection('informix')->table('books')->get();

// With pagination (uses FIRST/SKIP syntax)
$books = DB::connection('informix')
    ->table('books')
    ->skip(10)
    ->take(20)
    ->get();

// Insert and get ID
$id = DB::connection('informix')->table('books')->insertGetId([
    'title' => 'Laravel for Beginners',
    'author' => 'John Doe',
]);
```

### Eloquent Models

```php
<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Book extends Model
{
    protected $connection = 'informix';
    protected $table = 'books';
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
        Schema::connection('informix')->create('books', function (Blueprint $table) {
            $table->id();  // Creates SERIAL column
            $table->string('title', 255);
            $table->string('author', 100);
            $table->text('description')->nullable();
            $table->decimal('price', 10, 2);
            $table->timestamp('created_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::connection('informix')->dropIfExists('books');
    }
};
```

Run migrations for Informix:

```bash
php artisan migrate --database=informix
```

---

## Known Limitations

| Limitation | Description |
|------------|-------------|
| Multi-row INSERT | Automatically split into individual statements in a transaction |
| DROP IF EXISTS | Not supported natively; driver checks `systables` first |
| RETURNING clause | Not supported; use `DBINFO('sqlca.sqlerrd1')` for last SERIAL |
| ALTER TABLE | Limited column modification capabilities |
| `upsert()` | Not supported |
| `whereFullText()` | Not supported |
| `insertOrIgnore()` | Not supported |

---

## Troubleshooting

### Connection Refused

```
SQLSTATE[08001]: [IBM][CLI Driver] SQL30081N
```

**Solution**: Check if the Informix server is running and accessible on port 8888.

```bash
telnet 192.168.1.1 8888
```

### Database Not Found

```
SQLSTATE[42S02]: Base table or view not found
```

**Solution**: Ensure the database has been created on the Informix server.

### Authentication Failed

```
SQLSTATE[28000]: Invalid authorization specification
```

**Solution**: Verify username and password in `.env`.

### PDO_INFORMIX Not Loaded

```
could not find driver
```

**Solution**: Install PDO_INFORMIX extension and verify with `php -m | grep informix`.

---

## Quick Reference

| Environment Variable | Your Value | Description |
|---------------------|------------|-------------|
| `INFORMIX_HOST` | `192.168.1.1` | Database server IP |
| `INFORMIX_SERVICE` | `8888` | Database port |
| `INFORMIX_DATABASE` | `(to be created)` | Database name |
| `INFORMIX_SERVER` | `(ask DBA)` | Informix server instance |
| `INFORMIX_USERNAME` | `(your username)` | Database username |
| `INFORMIX_PASSWORD` | `(your password)` | Database password |

---

## References

- [Laravel-Informix GitHub Repository](https://github.com/hanamichisakuragiking/laravel-informix)
- [IBM Informix Documentation](https://www.ibm.com/docs/en/informix-servers)
- [PDO_INFORMIX Documentation](https://www.php.net/manual/en/ref.pdo-informix.php)
