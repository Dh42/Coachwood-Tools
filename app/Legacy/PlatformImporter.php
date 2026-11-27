<?php

namespace App\Legacy;

use App\Models\User;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Spatie\Permission\PermissionRegistrar;

/**
 * Copies the platform tables from the old app's database (the read-only
 * `legacy` connection) into this one. Plan section 3, "Platform import".
 * Safe to run again: each run brings the new app back in line with the old.
 *
 * This slice (3b) copies:
 * - roles and permissions, matched by name and guard
 * - Google Workspace domains, matched by domain
 * - users, with their IDs preserved because tool tables point at them
 * - each imported user's roles and direct permissions, re-mapped to the new
 *   role and permission IDs and replaced on every run, so the old app stays
 *   the source of truth until cutover
 * - the permissions given to each role, re-mapped the same way
 *
 * Later slices add tools, teams, the activity log and scheduled tasks.
 * It reports counts only, never names, emails or other values.
 */
final class PlatformImporter
{
    private const USER_COLUMNS = ['id', 'name', 'email', 'google_id', 'email_verified_at', 'disabled_at', 'password', 'remember_token', 'created_at', 'updated_at'];

    private const CHUNK = 1000;

    /** @var array<string, array{read: int, new: int, updated: int, removed: int}> */
    private array $report = [];

    public function __construct(
        private readonly ConnectionInterface $legacy,
        private readonly ConnectionInterface $db,
    ) {}

    public static function make(): self
    {
        return new self(DB::connection('legacy'), DB::connection());
    }

    /**
     * @return array<string, array{read: int, new: int, updated: int, removed: int}> per table
     *
     * @throws RuntimeException when an old user's email belongs to a different user here
     */
    public function run(bool $dryRun = false): array
    {
        $this->report = [];
        $this->refuseEmailClashes();

        $this->db->beginTransaction();

        try {
            $roles = $this->importByName('roles');
            $permissions = $this->importByName('permissions');
            $this->importDomains();
            $userIds = $this->importUsers();
            $this->importGrants('model_has_roles', 'role_id', $roles, $userIds);
            $this->importGrants('model_has_permissions', 'permission_id', $permissions, $userIds);
            $this->importRolePermissions($roles, $permissions);
        } catch (\Throwable $e) {
            $this->db->rollBack();

            throw $e;
        }

        $dryRun ? $this->db->rollBack() : $this->db->commit();

        app(PermissionRegistrar::class)->forgetCachedPermissions();

        return $this->report;
    }

    /**
     * Users keep their IDs, so an email that already belongs to a different
     * ID here would collide. Stop before writing anything and name the IDs.
     */
    private function refuseEmailClashes(): void
    {
        $clashes = [];

        $this->legacy->table('users')->select(['id', 'email'])->orderBy('id')->chunk(self::CHUNK, function ($rows) use (&$clashes) {
            $existing = $this->db->table('users')->whereIn('email', $rows->pluck('email'))->pluck('id', 'email');

            foreach ($rows as $row) {
                if (isset($existing[$row->email]) && (int) $existing[$row->email] !== (int) $row->id) {
                    $clashes[] = "old user {$row->id} matches new user {$existing[$row->email]}";
                }
            }
        });

        if ($clashes !== []) {
            throw new RuntimeException('These old users have an email that a different user already has here, so nothing was imported: '.implode('; ', $clashes).'.');
        }
    }

    /**
     * Roles or permissions, matched by name and guard.
     *
     * @return array<int, int> old ID => new ID
     */
    private function importByName(string $table): array
    {
        $old = $this->legacy->table($table)->orderBy('id')->get(['id', 'name', 'guard_name', 'created_at', 'updated_at']);
        $new = 0;

        foreach ($old as $row) {
            $new += $this->db->table($table)->insertOrIgnore([
                'name' => $row->name, 'guard_name' => $row->guard_name,
                'created_at' => $row->created_at, 'updated_at' => $row->updated_at,
            ]);
        }

        $ids = $this->db->table($table)->get(['id', 'name', 'guard_name'])
            ->mapWithKeys(fn ($row) => [$row->guard_name."\0".$row->name => (int) $row->id]);

        $this->report[$table] = ['read' => $old->count(), 'new' => $new, 'updated' => 0, 'removed' => 0];

        return $old->mapWithKeys(fn ($row) => [(int) $row->id => $ids[$row->guard_name."\0".$row->name]])->all();
    }

    private function importDomains(): void
    {
        $old = $this->legacy->table('google_workspace_domains')->orderBy('id')->get(['domain', 'created_at', 'updated_at']);
        $new = 0;

        foreach ($old as $row) {
            $new += $this->db->table('google_workspace_domains')->insertOrIgnore([
                'domain' => $row->domain, 'created_at' => $row->created_at, 'updated_at' => $row->updated_at,
            ]);
        }

        $this->report['google_workspace_domains'] = ['read' => $old->count(), 'new' => $new, 'updated' => 0, 'removed' => 0];
    }

    /** @return list<int> the imported user IDs */
    private function importUsers(): array
    {
        $ids = [];
        $report = ['read' => 0, 'new' => 0, 'updated' => 0, 'removed' => 0];

        $this->legacy->table('users')->select(self::USER_COLUMNS)->orderBy('id')->chunk(self::CHUNK, function ($rows) use (&$ids, &$report) {
            $existing = $this->db->table('users')->whereIn('id', $rows->pluck('id'))->count();
            $this->db->table('users')->upsert(
                $rows->map(fn ($row) => (array) $row)->all(),
                ['id'],
                array_values(array_diff(self::USER_COLUMNS, ['id'])),
            );

            $report['read'] += $rows->count();
            $report['updated'] += $existing;
            $report['new'] += $rows->count() - $existing;
            array_push($ids, ...$rows->pluck('id')->map(fn ($id) => (int) $id));
        });

        $this->report['users'] = $report;

        return $ids;
    }

    /**
     * A user's roles or direct permissions: replaced with the old app's,
     * re-mapped to the new IDs.
     *
     * @param  array<int, int>  $map  old ID => new ID
     * @param  list<int>  $userIds
     */
    private function importGrants(string $table, string $key, array $map, array $userIds): void
    {
        $imported = array_flip($userIds);
        $rows = $this->legacy->table($table)->where('model_type', User::class)->get([$key, 'model_id'])
            ->filter(fn ($row) => isset($map[(int) $row->{$key}], $imported[(int) $row->model_id]))
            ->map(fn ($row) => [$key => $map[(int) $row->{$key}], 'model_type' => User::class, 'model_id' => (int) $row->model_id]);

        $before = collect();
        foreach (array_chunk($userIds, self::CHUNK) as $chunk) {
            $before = $before->merge($this->db->table($table)->where('model_type', User::class)->whereIn('model_id', $chunk)->get([$key, 'model_id'])
                ->map(fn ($row) => [$key => (int) $row->{$key}, 'model_type' => User::class, 'model_id' => (int) $row->model_id]));
            $this->db->table($table)->where('model_type', User::class)->whereIn('model_id', $chunk)->delete();
        }
        foreach ($rows->chunk(self::CHUNK) as $chunk) {
            $this->db->table($table)->insert($chunk->values()->all());
        }

        $this->report[$table] = $this->replacedCounts($rows, $before);
    }

    /**
     * @param  array<int, int>  $roles  old ID => new ID
     * @param  array<int, int>  $permissions  old ID => new ID
     */
    private function importRolePermissions(array $roles, array $permissions): void
    {
        $rows = $this->legacy->table('role_has_permissions')->get(['permission_id', 'role_id'])
            ->filter(fn ($row) => isset($roles[(int) $row->role_id], $permissions[(int) $row->permission_id]))
            ->map(fn ($row) => ['permission_id' => $permissions[(int) $row->permission_id], 'role_id' => $roles[(int) $row->role_id]]);

        $before = $this->db->table('role_has_permissions')->whereIn('role_id', array_values($roles))->get(['permission_id', 'role_id'])
            ->map(fn ($row) => ['permission_id' => (int) $row->permission_id, 'role_id' => (int) $row->role_id]);
        $this->db->table('role_has_permissions')->whereIn('role_id', array_values($roles))->delete();
        foreach ($rows->chunk(self::CHUNK) as $chunk) {
            $this->db->table('role_has_permissions')->insert($chunk->values()->all());
        }

        $this->report['role_has_permissions'] = $this->replacedCounts($rows, $before);
    }

    /**
     * Counts for a grant table that was replaced: rows that were not there
     * before, and rows that are gone.
     *
     * @param  Collection<int, array<string, mixed>>  $after
     * @param  Collection<int, array<string, mixed>>  $before
     * @return array{read: int, new: int, updated: int, removed: int}
     */
    private function replacedCounts($after, $before): array
    {
        $key = fn (array $row) => implode('|', $row);
        $afterKeys = $after->map($key)->flip();
        $beforeKeys = $before->map($key)->flip();

        return [
            'read' => $after->count(),
            'new' => $afterKeys->diffKeys($beforeKeys)->count(),
            'updated' => 0,
            'removed' => $beforeKeys->diffKeys($afterKeys)->count(),
        ];
    }
}
