<?php

namespace Hanamichisakuragiking\LaravelInformix\Tests\Integration;

use Hanamichisakuragiking\LaravelInformix\InformixConnection;
use Hanamichisakuragiking\LaravelInformix\InformixServiceProvider;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Schema\Blueprint;
use Orchestra\Testbench\TestCase;
use PDO;
use PHPUnit\Framework\Attributes\Group;

#[Group('integration')]
class InformixIntegrationTest extends TestCase
{
    private ?InformixConnection $connection = null;
    private ?string $users = null;
    private ?string $posts = null;

    protected function getPackageProviders($app): array
    {
        return [InformixServiceProvider::class];
    }

    protected function defineEnvironment($app): void
    {
        $storage = sys_get_temp_dir() . '/ifxt-storage';
        foreach (['logs', 'framework/cache', 'framework/sessions', 'framework/views'] as $directory) {
            if (!is_dir($storage . '/' . $directory)) {
                mkdir($storage . '/' . $directory, 0700, true);
            }
        }
        $app->useStoragePath($storage);
        $app['config']->set('logging.default', 'stderr');
        $app['config']->set('database.connections.informix', [
            'driver' => 'informix',
            'host' => getenv('INFORMIX_HOST') ?: '10.116.165.44',
            'service' => getenv('INFORMIX_SERVICE') ?: '9088',
            'server' => getenv('INFORMIX_SERVER') ?: '',
            'database' => getenv('INFORMIX_DATABASE') ?: '',
            'username' => getenv('INFORMIX_USERNAME') ?: '',
            'password' => getenv('INFORMIX_PASSWORD') ?: '',
            'protocol' => getenv('INFORMIX_PROTOCOL') ?: 'onsoctcp',
            'db_locale' => getenv('INFORMIX_DB_LOCALE') ?: 'en_US.utf8',
            'client_locale' => getenv('INFORMIX_CLIENT_LOCALE') ?: 'en_US.utf8',
            'prefix' => '',
        ]);
    }

    protected function setUp(): void
    {
        parent::setUp();

        if (getenv('INFORMIX_INTEGRATION') !== '1') {
            $this->markTestSkipped('Set INFORMIX_INTEGRATION=1 to enable real database writes.');
        }

        $this->assertContains('informix', PDO::getAvailableDrivers(), 'PDO_INFORMIX is required.');
        foreach (['INFORMIX_SERVER', 'INFORMIX_DATABASE', 'INFORMIX_USERNAME'] as $key) {
            $this->assertNotEmpty(getenv($key), "Set {$key} in .env.integration.");
            $this->assertStringNotContainsString('REPLACE_', getenv($key));
        }

        $this->connection = $this->app['db']->connection('informix');
        $schema = $this->connection->getSchemaBuilder();
        $suffix = bin2hex(random_bytes(5));
        $users = 'ifxt_u_' . $suffix;
        $posts = 'ifxt_p_' . $suffix;

        // Never adopt or delete a table that existed before this test.
        $this->assertFalse($schema->hasTable($users));
        $this->assertFalse($schema->hasTable($posts));
        $this->users = $users;
        $this->posts = $posts;

        $schema->create($users, function (Blueprint $table) {
            $table->increments('id');
            $table->string('name', 100);
            $table->boolean('active');
            $table->string('token', 100)->nullable();
        });
        $schema->create($posts, function (Blueprint $table) {
            $table->increments('id');
            $table->integer('user_id');
            $table->string('title', 100);
        });
    }

    protected function tearDown(): void
    {
        try {
            if ($this->connection !== null) {
                while ($this->connection->transactionLevel() > 0) {
                    $this->connection->rollBack();
                }
                foreach ([$this->posts, $this->users] as $table) {
                    if ($table !== null) {
                        $this->connection->getSchemaBuilder()->dropIfExists($table);
                    }
                }
            }
        } finally {
            parent::tearDown();
        }
    }

    public function test_real_connection_schema_and_fetch_modes(): void
    {
        $schema = $this->connection->getSchemaBuilder();
        $this->assertTrue($schema->hasTable($this->users));
        $this->assertTrue($schema->hasColumn($this->users, 'name'));
        $sql = 'SELECT 1 AS probe FROM systables WHERE tabid = 1';
        $this->assertSame(1, (int) $this->connection->selectOne($sql)->probe);
        $rows = $this->connection->select($sql, [], true, [PDO::FETCH_ASSOC]);
        $this->assertSame(1, (int) $rows[0]['probe']);
    }

    public function test_crud_and_special_character_round_trip(): void
    {
        $token = '$2y$10$abc\\path';
        $id = $this->connection->table($this->users)->insertGetId([
            'name' => "O'Brien", 'active' => true, 'token' => $token,
        ]);
        $this->assertGreaterThan(0, $id);
        $row = $this->connection->table($this->users)->where('id', $id)->first();
        $this->assertSame("O'Brien", $row->name);
        $this->assertSame($token, $row->token);
        $this->assertSame('t', $row->active);
        $this->assertSame(1, $this->connection->table($this->users)->where('id', $id)->update(['name' => 'Updated']));
        $this->assertSame('Updated', $this->connection->table($this->users)->where('id', $id)->first()->name);
        $this->assertSame(1, $this->connection->table($this->users)->where('id', $id)->delete());
        $this->assertSame(0, $this->connection->table($this->users)->count());
    }

    public function test_batch_insert_aggregate_and_pagination(): void
    {
        $this->connection->table($this->users)->insert([
            ['name' => 'Alpha', 'active' => true],
            ['name' => 'Beta', 'active' => false],
            ['name' => 'Gamma', 'active' => true],
        ]);
        $this->assertSame(3, $this->connection->table($this->users)->count());
        $this->assertSame(2, $this->connection->table($this->users)->where('active', true)->count());
        $rows = $this->connection->table($this->users)->orderBy('name')->offset(1)->limit(1)->get();
        $this->assertCount(1, $rows);
        $this->assertSame('Beta', $rows[0]->name);
    }

    public function test_transaction_commit_and_rollback(): void
    {
        $this->connection->beginTransaction();
        $this->connection->table($this->users)->insert(['name' => 'Rolled back', 'active' => false]);
        $this->connection->rollBack();
        $this->assertSame(0, $this->connection->table($this->users)->count());
        $this->connection->transaction(function () {
            $this->connection->table($this->users)->insert(['name' => 'Committed', 'active' => true]);
        });
        $this->assertSame(1, $this->connection->table($this->users)->count());
    }

    public function test_eloquent_relationships_eager_loading_and_join(): void
    {
        IntegrationPost::$testTable = $this->posts;
        $user = new IntegrationUser();
        $user->setTable($this->users);
        $user->fill(['name' => 'Parent', 'active' => true])->save();
        $this->assertGreaterThan(0, $user->id);
        $user->posts()->create(['title' => 'Child']);
        $loaded = $user->newQuery()->with('posts')->findOrFail($user->id);
        $this->assertCount(1, $loaded->posts);
        $this->assertSame('Child', $loaded->posts[0]->title);
        $joined = $this->connection->table($this->users . ' as u')
            ->join($this->posts . ' as p', 'p.user_id', '=', 'u.id')
            ->select('u.name', 'p.title')->first();
        $this->assertSame('Parent', $joined->name);
        $this->assertSame('Child', $joined->title);
    }
}

class IntegrationUser extends Model
{
    protected $connection = 'informix';
    protected $guarded = [];
    public $timestamps = false;

    public function posts()
    {
        return $this->hasMany(IntegrationPost::class, 'user_id');
    }
}

class IntegrationPost extends Model
{
    public static string $testTable;
    protected $connection = 'informix';
    protected $guarded = [];
    public $timestamps = false;

    public function __construct(array $attributes = [])
    {
        parent::__construct($attributes);
        $this->setTable(static::$testTable);
    }
}
