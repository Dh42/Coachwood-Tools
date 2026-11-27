<?php

namespace Tests\Feature\Legacy;

use App\Models\GoogleWorkspaceDomain;
use App\Models\User;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * `legacy:import-platform` against a made-up old database: the in-memory
 * `legacy` connection with the old app's table shapes.
 */
class ImportPlatformTest extends TestCase
{
    use RefreshDatabase;

    private const OLD_TABLES = ['users', 'roles', 'permissions', 'model_has_roles', 'model_has_permissions', 'role_has_permissions', 'google_workspace_domains'];

    protected function setUp(): void
    {
        parent::setUp();

        $schema = Schema::connection('legacy');
        foreach (self::OLD_TABLES as $table) {
            $schema->dropIfExists($table);
        }

        $schema->create('users', function (Blueprint $t) {
            $t->id();
            $t->string('name');
            $t->string('email')->unique();
            $t->string('google_id')->nullable();
            $t->timestamp('email_verified_at')->nullable();
            $t->timestamp('disabled_at')->nullable();
            $t->string('password')->nullable();
            $t->string('remember_token')->nullable();
            $t->timestamps();
        });
        foreach (['roles', 'permissions'] as $table) {
            $schema->create($table, function (Blueprint $t) {
                $t->id();
                $t->string('name');
                $t->string('guard_name');
                $t->timestamps();
            });
        }
        $schema->create('model_has_roles', fn (Blueprint $t) => [$t->unsignedBigInteger('role_id'), $t->string('model_type'), $t->unsignedBigInteger('model_id')]);
        $schema->create('model_has_permissions', fn (Blueprint $t) => [$t->unsignedBigInteger('permission_id'), $t->string('model_type'), $t->unsignedBigInteger('model_id')]);
        $schema->create('role_has_permissions', fn (Blueprint $t) => [$t->unsignedBigInteger('permission_id'), $t->unsignedBigInteger('role_id')]);
        $schema->create('google_workspace_domains', function (Blueprint $t) {
            $t->id();
            $t->string('domain');
            $t->timestamps();
        });

        $old = DB::connection('legacy');
        // Old role IDs differ from the new ones, to prove grants are re-mapped.
        $old->table('roles')->insert([
            ['id' => 7, 'name' => 'user', 'guard_name' => 'web'],
            ['id' => 8, 'name' => 'admin', 'guard_name' => 'web'],
        ]);
        $old->table('permissions')->insert([
            ['id' => 30, 'name' => 'tool.example-one', 'guard_name' => 'web'],
            ['id' => 31, 'name' => 'tool.example-two', 'guard_name' => 'web'],
        ]);
        $old->table('users')->insert([
            ['id' => 3, 'name' => 'Admin Example', 'email' => 'admin@example.test', 'password' => Hash::make('old-password'), 'google_id' => null, 'disabled_at' => null],
            ['id' => 5, 'name' => 'Google Example', 'email' => 'google@example.test', 'password' => null, 'google_id' => 'google-5', 'disabled_at' => null],
            ['id' => 9, 'name' => 'Disabled Example', 'email' => 'gone@example.test', 'password' => Hash::make('old-password'), 'google_id' => null, 'disabled_at' => '2026-10-01 00:00:00'],
        ]);
        $old->table('model_has_roles')->insert([
            ['role_id' => 8, 'model_type' => User::class, 'model_id' => 3],
            ['role_id' => 7, 'model_type' => User::class, 'model_id' => 5],
            ['role_id' => 7, 'model_type' => User::class, 'model_id' => 9],
        ]);
        $old->table('model_has_permissions')->insert([
            ['permission_id' => 31, 'model_type' => User::class, 'model_id' => 5],
        ]);
        $old->table('role_has_permissions')->insert([
            ['permission_id' => 30, 'role_id' => 7],
        ]);
        $old->table('google_workspace_domains')->insert([['domain' => 'example.test']]);
    }

    public function test_it_copies_users_with_their_ids_roles_and_permissions(): void
    {
        $this->artisan('legacy:import-platform')->assertSuccessful();

        $this->assertSame([3, 5, 9], User::query()->orderBy('id')->pluck('id')->all());

        $admin = User::find(3);
        $this->assertTrue($admin->hasRole('admin'));
        $this->assertTrue(Hash::check('old-password', $admin->password));

        $google = User::find(5);
        $this->assertNull($google->password);
        $this->assertSame('google-5', $google->google_id);
        $this->assertTrue($google->hasRole('user'));
        $this->assertTrue($google->can('tool.example-two'));
        $this->assertTrue($google->can('tool.example-one'), 'granted through the user role');

        $this->assertTrue(User::find(9)->isDisabled());
        $this->assertSame(['example.test'], GoogleWorkspaceDomain::query()->pluck('domain')->all());
    }

    public function test_old_users_can_sign_in_with_their_old_password_after_the_import(): void
    {
        $this->artisan('legacy:import-platform')->assertSuccessful();

        $this->post('/login', ['email' => 'admin@example.test', 'password' => 'old-password'])
            ->assertRedirect(route('dashboard', absolute: false));
        $this->assertAuthenticatedAs(User::find(3));
    }

    public function test_running_it_again_changes_nothing_and_adds_no_rows(): void
    {
        $this->artisan('legacy:import-platform')->assertSuccessful();
        $snapshot = $this->newDatabaseRows();

        $this->artisan('legacy:import-platform')->assertSuccessful();

        $this->assertEquals($snapshot, $this->newDatabaseRows());
        $this->assertSame(2, Role::count());
    }

    public function test_a_rerun_brings_changed_grants_back_in_line_with_the_old_app(): void
    {
        $this->artisan('legacy:import-platform')->assertSuccessful();
        User::find(5)->assignRole('admin');

        DB::connection('legacy')->table('users')->where('id', 3)->update(['name' => 'Renamed Example']);
        $this->artisan('legacy:import-platform')->assertSuccessful();

        $this->assertFalse(User::find(5)->hasRole('admin'));
        $this->assertSame('Renamed Example', User::find(3)->name);
    }

    public function test_it_keeps_the_new_apps_display_preferences(): void
    {
        $this->artisan('legacy:import-platform')->assertSuccessful();
        User::find(3)->forceFill(['theme' => 'daylight'])->save();

        $this->artisan('legacy:import-platform')->assertSuccessful();

        $this->assertSame('daylight', User::find(3)->theme);
    }

    public function test_a_dry_run_saves_nothing(): void
    {
        $this->artisan('legacy:import-platform', ['--dry-run' => true])
            ->expectsOutputToContain('Dry run: nothing was saved.')
            ->assertSuccessful();

        $this->assertSame(0, User::count());
    }

    public function test_an_email_owned_by_a_different_user_here_stops_the_import_before_any_write(): void
    {
        User::factory()->create(['id' => 40, 'email' => 'admin@example.test']);

        $this->artisan('legacy:import-platform')
            ->expectsOutputToContain('old user 3 matches new user 40')
            ->assertFailed();

        $this->assertSame([40], User::query()->pluck('id')->all());
        $this->assertSame(0, GoogleWorkspaceDomain::count());
    }

    public function test_it_prints_counts_and_never_names_or_emails(): void
    {
        $this->artisan('legacy:import-platform')
            ->doesntExpectOutputToContain('example.test')
            ->doesntExpectOutputToContain('Example')
            ->expectsTable(['Table', 'Rows in old app', 'New here', 'Updated', 'Removed here'], [
                ['roles', 2, 0, 0, 0],
                ['permissions', 2, 2, 0, 0],
                ['google_workspace_domains', 1, 1, 0, 0],
                ['users', 3, 3, 0, 0],
                ['model_has_roles', 3, 3, 0, 0],
                ['model_has_permissions', 1, 1, 0, 0],
                ['role_has_permissions', 1, 1, 0, 0],
            ])
            ->assertSuccessful();
    }

    public function test_a_rerun_reports_only_what_changed(): void
    {
        $this->artisan('legacy:import-platform')->assertSuccessful();
        User::find(5)->assignRole('admin');

        $this->artisan('legacy:import-platform')
            ->expectsTable(['Table', 'Rows in old app', 'New here', 'Updated', 'Removed here'], [
                ['roles', 2, 0, 0, 0],
                ['permissions', 2, 0, 0, 0],
                ['google_workspace_domains', 1, 0, 0, 0],
                ['users', 3, 0, 3, 0],
                ['model_has_roles', 3, 0, 0, 1],
                ['model_has_permissions', 1, 0, 0, 0],
                ['role_has_permissions', 1, 0, 0, 0],
            ])
            ->assertSuccessful();
    }

    /** @return array<string, list<array<string, mixed>>> */
    private function newDatabaseRows(): array
    {
        $tables = ['users' => 'id', 'roles' => 'id', 'permissions' => 'id', 'google_workspace_domains' => 'id', 'model_has_roles' => 'model_id', 'model_has_permissions' => 'model_id', 'role_has_permissions' => 'role_id'];

        return collect($tables)->map(fn (string $order, string $table) => DB::table($table)->orderBy($order)->get()->map(fn ($row) => (array) $row)->all())->all();
    }
}
