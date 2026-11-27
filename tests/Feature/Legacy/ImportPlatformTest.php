<?php

namespace Tests\Feature\Legacy;

use App\Models\GoogleWorkspaceDomain;
use App\Models\Team;
use App\Models\Tool;
use App\Models\User;
use App\Tools\ToolRegistry;
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

    private const OLD_TABLES = ['users', 'roles', 'permissions', 'model_has_roles', 'model_has_permissions', 'role_has_permissions', 'google_workspace_domains', 'activity_log', 'tools', 'teams', 'team_user'];

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
        $schema->create('teams', function (Blueprint $t) {
            $t->id();
            $t->string('name')->unique();
            $t->text('description')->nullable();
            $t->timestamps();
        });
        $schema->create('team_user', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('team_id');
            $t->unsignedBigInteger('user_id');
            $t->timestamps();
        });
        $schema->create('tools', function (Blueprint $t) {
            $t->id();
            $t->string('name');
            $t->text('description')->nullable();
            $t->string('slug')->unique();
            $t->string('icon')->nullable();
            $t->string('url')->nullable();
            $t->boolean('active')->default(true);
            $t->unsignedInteger('sort_order')->default(0);
            $t->timestamps();
        });
        $schema->create('activity_log', function (Blueprint $t) {
            $t->id();
            $t->string('log_name')->nullable();
            $t->text('description');
            $t->nullableMorphs('subject');
            $t->string('event')->nullable();
            $t->nullableMorphs('causer');
            $t->json('properties')->nullable();
            $t->uuid('batch_uuid')->nullable();
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
        $old->table('teams')->insert([['id' => 4, 'name' => 'Amazon', 'description' => 'Made-up team']]);
        $old->table('team_user')->insert([
            ['team_id' => 4, 'user_id' => 3],
            ['team_id' => 4, 'user_id' => 5],
        ]);
        $old->table('tools')->insert([
            ['name' => 'Ported Tool', 'slug' => 'ported-tool', 'url' => '/tools/ported-tool', 'active' => false, 'sort_order' => 9],
            ['name' => 'Not Ported Yet', 'slug' => 'not-ported-yet', 'url' => '/tools/not-ported-yet', 'active' => true, 'sort_order' => 2],
        ]);
        $old->table('activity_log')->insert([
            ['id' => 1, 'log_name' => 'auth', 'description' => 'Login succeeded', 'causer_type' => User::class, 'causer_id' => 3, 'properties' => '{"method":"google"}', 'created_at' => '2026-10-01 09:00:00', 'updated_at' => '2026-10-01 09:00:00'],
            ['id' => 2, 'log_name' => 'admin', 'description' => 'Tool created', 'causer_type' => User::class, 'causer_id' => 3, 'properties' => '[]', 'created_at' => '2026-10-02 09:00:00', 'updated_at' => '2026-10-02 09:00:00'],
        ]);
    }

    public function test_it_copies_users_with_their_ids_roles_and_permissions(): void
    {
        $this->artisan('legacy:import-platform')->assertSuccessful();

        $this->assertSame([3, 5, 9], User::query()->orderBy('id')->pluck('id')->all());

        $this->assertTrue(User::find(3)->hasRole('admin'));

        $google = User::find(5);
        $this->assertSame('google-5', $google->google_id);
        $this->assertTrue($google->hasRole('user'));
        $this->assertTrue($google->can('tool.example-two'));
        $this->assertTrue($google->can('tool.example-one'), 'granted through the user role');

        $this->assertTrue(User::find(9)->isDisabled());
        $this->assertSame(['example.test'], GoogleWorkspaceDomain::query()->pluck('domain')->all());
    }

    public function test_it_copies_no_password_hashes_or_remember_tokens(): void
    {
        // Sign-in is Google only (decision 2026-11-27), so old passwords
        // would only be secrets kept for nothing.
        DB::connection('legacy')->table('users')->where('id', 3)->update(['remember_token' => 'old-token']);

        $this->artisan('legacy:import-platform')->assertSuccessful();

        $this->assertSame(0, User::query()->whereNotNull('password')->orWhereNotNull('remember_token')->count());
    }

    public function test_a_password_hash_already_here_is_cleared_on_the_next_run(): void
    {
        $this->artisan('legacy:import-platform')->assertSuccessful();
        User::find(3)->forceFill(['password' => Hash::make('something')])->save();

        $this->artisan('legacy:import-platform')->assertSuccessful();

        $this->assertNull(User::find(3)->password);
    }

    public function test_a_different_person_already_holding_an_old_users_id_stops_the_import(): void
    {
        // Google sign-in creates users here before cutover, so a new user can
        // take an ID the old app hands out later. Same ID, different email
        // and different Google ID: a different person, never overwrite.
        User::factory()->create(['id' => 9, 'email' => 'new-here@example.test', 'google_id' => 'google-new']);

        $this->artisan('legacy:import-platform')
            ->expectsOutputToContain('old user 9 has the ID of a different user here')
            ->assertFailed();

        $this->assertSame('new-here@example.test', User::find(9)->email);
        $this->assertSame(1, User::count());
    }

    public function test_an_email_change_in_the_old_app_still_updates_the_same_person(): void
    {
        $this->artisan('legacy:import-platform')->assertSuccessful();
        DB::connection('legacy')->table('users')->where('id', 5)->update(['email' => 'renamed@example.test']);

        $this->artisan('legacy:import-platform')->assertSuccessful();

        $this->assertSame('renamed@example.test', User::find(5)->email);
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
                ['activity_log', 2, 2, 0, 0],
                ['tools', 2, 0, 0, 0],
                ['teams', 1, 1, 0, 0],
                ['team_user', 2, 2, 0, 0],
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
                ['activity_log', 2, 0, 2, 0],
                ['tools', 2, 0, 0, 0],
                ['teams', 1, 0, 1, 0],
                ['team_user', 2, 0, 0, 0],
            ])
            ->assertSuccessful();
    }

    public function test_it_copies_the_activity_log_and_keeps_this_apps_own_entries(): void
    {
        // Logged here before the import. On a fresh table it takes ID 1,
        // which an old row also has.
        $own = activity('auth')->log('Logged out');

        $this->artisan('legacy:import-platform')->assertSuccessful();
        $this->artisan('legacy:import-platform')->assertSuccessful();

        $rows = DB::table('activity_log')->orderBy('created_at')->get();
        $this->assertCount(3, $rows);
        $this->assertSame('Logged out', DB::table('activity_log')->where('id', $own->id)->value('description'));
        $this->assertNull(DB::table('activity_log')->where('id', $own->id)->value('legacy_id'));

        $copied = DB::table('activity_log')->where('legacy_id', 1)->first();
        $this->assertSame('Login succeeded', $copied->description);
        $this->assertSame(3, (int) $copied->causer_id);
        $this->assertSame('2026-10-01 09:00:00', (string) $copied->created_at);
        $this->assertSame('google', json_decode($copied->properties, true)['method']);
    }

    public function test_it_copies_the_old_order_and_active_flag_onto_tools_registered_here_only(): void
    {
        ToolRegistry::register(['slug' => 'ported-tool', 'name' => 'Ported Tool', 'group' => 'Group set here', 'url' => '/tools/ported-tool']);

        $this->artisan('legacy:import-platform')
            ->expectsOutputToContain('Import complete.')
            ->assertSuccessful();

        $tool = Tool::where('slug', 'ported-tool')->sole();
        $this->assertFalse($tool->active);
        $this->assertSame(9, $tool->sort_order);
        $this->assertSame('Group set here', $tool->group);
        $this->assertDatabaseMissing('tools', ['slug' => 'not-ported-yet']);
    }

    public function test_it_copies_teams_with_their_ids_and_replaces_their_members(): void
    {
        $this->artisan('legacy:import-platform')->assertSuccessful();

        $team = Team::findOrFail(4);
        $this->assertSame('Amazon', $team->name);
        $this->assertEqualsCanonicalizing([3, 5], $team->members()->pluck('users.id')->all());

        $team->members()->attach(9);
        $this->artisan('legacy:import-platform')->assertSuccessful();

        $this->assertEqualsCanonicalizing([3, 5], $team->members()->pluck('users.id')->all());
    }

    public function test_a_team_name_used_by_a_different_team_here_stops_the_import(): void
    {
        Team::forceCreate(['id' => 40, 'name' => 'Amazon']);

        $this->artisan('legacy:import-platform')
            ->expectsOutputToContain('old team 4 matches new team 40')
            ->assertFailed();

        $this->assertSame(0, User::count());
    }

    /** @return array<string, list<array<string, mixed>>> */
    private function newDatabaseRows(): array
    {
        $tables = ['users' => 'id', 'roles' => 'id', 'permissions' => 'id', 'google_workspace_domains' => 'id', 'model_has_roles' => 'model_id', 'model_has_permissions' => 'model_id', 'role_has_permissions' => 'role_id', 'activity_log' => 'id', 'teams' => 'id', 'team_user' => 'user_id'];

        return collect($tables)->map(fn (string $order, string $table) => DB::table($table)->orderBy($order)->get()->map(fn ($row) => (array) $row)->all())->all();
    }
}
