# PDO Informix Driver Known Issues & Workarounds

This document describes known limitations of the PDO Informix driver (`PDO_INFORMIX`) and recommended workarounds when using Laravel with Informix databases.

---

## 1. TEXT/BLOB Column Segmentation Fault (CRITICAL)

### Issue

The PDO Informix driver (version 1.3.6 and earlier) causes a **segmentation fault** (SIGSEGV, exit code 139) when reading TEXT or BLOB columns from the database.

### Technical Details

- **Root Cause:** The PDO Informix C extension returns TEXT/BLOB data as PHP stream resources instead of strings
- **Failure Point:** When PHP code attempts to access the resource (via `stream_get_contents()`, string casting, or functions like `htmlspecialchars()` / `base64_decode()`), the C extension crashes
- **Affected Component:** `informix_driver.c` in the PDO_INFORMIX extension
- **NOT a Laravel issue:** This is a bug in the underlying PDO driver

### Symptoms

```
Exit code: 139
Segmentation fault (core dumped)
```

Or in Laravel logs:
```
htmlspecialchars(): Argument #1 ($string) must be of type string, resource given
base64_decode(): Argument #1 ($string) must be of type string, resource given
```

### Workarounds

#### Option 1: Avoid TEXT columns (Recommended)

Use `VARCHAR` instead of `TEXT` in your migrations:

```php
// ❌ DON'T USE - causes segfault
Schema::create('posts', function (Blueprint $table) {
    $table->text('content');
});

// ✅ USE THIS INSTEAD
Schema::create('posts', function (Blueprint $table) {
    $table->string('content', 2000);  // or up to 32739 for LVARCHAR
});
```

#### Option 2: Model Trait for Resource Conversion

If you must use TEXT columns, create a trait to safely handle resources:

```php
<?php

namespace App\Models\Traits;

trait HandlesInformixText
{
    /**
     * TEXT fields that Informix returns as resources.
     * Define this in your model: protected $informixTextFields = ['content'];
     */
    
    public function getAttribute($key)
    {
        $value = parent::getAttribute($key);
        
        // Convert resources to strings for defined TEXT fields
        if (isset($this->informixTextFields) && in_array($key, $this->informixTextFields)) {
            return $this->convertResourceToString($value);
        }
        
        // Also handle any unexpected resources
        if (is_resource($value)) {
            return $this->convertResourceToString($value);
        }
        
        return $value;
    }

    protected function convertResourceToString($value)
    {
        if ($value === null) {
            return null;
        }
        
        if (is_string($value)) {
            return $value;
        }
        
        if (is_resource($value)) {
            try {
                $content = @stream_get_contents($value);
                return $content !== false ? $content : '';
            } catch (\Throwable $e) {
                return '';
            }
        }
        
        return '';
    }
}
```

Usage:
```php
class Post extends Model
{
    use HandlesInformixText;
    
    protected $informixTextFields = ['content', 'summary'];
}
```

> ⚠️ **Note:** This trait may not prevent segfaults in all cases, as the crash occurs at the C extension level before PHP can handle it.

#### Option 3: Raw SQL with CAST

Cast TEXT to VARCHAR in queries:

```php
$posts = DB::select("
    SELECT id, title, CAST(content AS VARCHAR(2000)) AS content 
    FROM posts
");
```

---

## 2. Sessions Table TEXT Column

### Issue

Laravel's default database session driver stores the payload in a TEXT column, which triggers the same segfault issue.

### Workaround

Use file-based sessions instead of database sessions:

```env
# .env
SESSION_DRIVER=file
```

Or use Redis/Memcached if available:
```env
SESSION_DRIVER=redis
```

---

## 3. Foreign Key ON DELETE Syntax

### Issue

Informix has different syntax requirements for foreign key constraints. The standard Laravel migration syntax may fail:

```
SQLSTATE[42000]: Syntax error or access violation: -201 A syntax error has occurred
```

### Workaround

Avoid `onDelete()` constraint modifiers:

```php
// ❌ DON'T USE
$table->foreignId('user_id')->constrained()->onDelete('cascade');
$table->foreignId('category_id')->constrained()->onDelete('set null');

// ✅ USE THIS INSTEAD
$table->unsignedBigInteger('user_id');
$table->unsignedBigInteger('category_id')->nullable();

// Handle cascades in application logic or triggers
```

---

## 4. Transaction Commit Errors

### Issue

Eloquent's `attach()`, `sync()`, and other pivot table methods use transactions that may fail on Informix:

```
PDO::commit(): SQLSTATE[HYC00]: Optional feature not implemented: -11092 Driver not capable
```

### Workaround

Use raw DB inserts for pivot table operations:

```php
// ❌ DON'T USE
$user->roles()->attach($roleId);
$user->roles()->sync($roleIds);

// ✅ USE THIS INSTEAD
DB::table('role_user')->insert([
    'user_id' => $user->id,
    'role_id' => $roleId,
    'created_at' => now(),
    'updated_at' => now(),
]);

// For sync, manually delete and insert
DB::table('role_user')->where('user_id', $user->id)->delete();
foreach ($roleIds as $roleId) {
    DB::table('role_user')->insert([
        'user_id' => $user->id,
        'role_id' => $roleId,
        'created_at' => now(),
        'updated_at' => now(),
    ]);
}
```

---

## 5. ENUM Type Support

### Issue

Informix doesn't have a native ENUM type like MySQL.

### Workaround

Use CHECK constraints or VARCHAR with application-level validation:

```php
// In migration
$table->string('status', 20)->default('pending');

// In model
protected $casts = [
    'status' => 'string',
];

const STATUS_PENDING = 'pending';
const STATUS_APPROVED = 'approved';
const STATUS_REJECTED = 'rejected';

public static function getStatuses(): array
{
    return [self::STATUS_PENDING, self::STATUS_APPROVED, self::STATUS_REJECTED];
}
```

---

## Summary Table

| Issue | Severity | Workaround |
|-------|----------|------------|
| TEXT/BLOB segfault | CRITICAL | Use VARCHAR instead of TEXT |
| Session TEXT column | HIGH | Use `SESSION_DRIVER=file` |
| Foreign key syntax | MEDIUM | Avoid `onDelete()` modifiers |
| Transaction commits | MEDIUM | Use raw DB inserts for pivots |
| ENUM type | LOW | Use VARCHAR with validation |

---

## Reporting Issues

If you encounter additional PDO Informix issues:

1. Check if it's a driver-level issue (segfault, C extension crash)
2. Check if it's a SQL syntax difference (Informix vs MySQL/PostgreSQL)
3. Report driver issues to the PDO_INFORMIX maintainers
4. Report Laravel integration issues to this package

---

## References

- [PDO_INFORMIX on PECL](https://pecl.php.net/package/PDO_INFORMIX)
- [IBM Informix Documentation](https://www.ibm.com/docs/en/informix-servers)
- [Informix SQL Reference](https://www.ibm.com/docs/en/informix-servers/14.10?topic=reference-sql-syntax)

