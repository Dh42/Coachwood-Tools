<?php

namespace App\Legacy;

use App\Cutover\Cutover;
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
 * - users, with their IDs preserved because tool tables point at them. No
 *   password hashes or remember tokens, and any already here are cleared:
 *   sign-in is Google only (decision 2026-11-27)
 * - each imported user's roles and direct permissions, re-mapped to the new
 *   role and permission IDs and replaced on every run, so the old app stays
 *   the source of truth until cutover
 * - the permissions given to each role, re-mapped the same way
 * - the activity log (3c), matched on `legacy_id`, the old row's ID. This
 *   app's own rows are never touched. Read in chunks with a pause between
 * - tools (3d), matched by slug: the old app's order and active flag are
 *   copied onto tools already registered here. Tools not ported yet are
 *   not created; their links would lead nowhere in this app
 * - teams (3e), with their IDs preserved, and each imported team's members,
 *   replaced on every run. A team name already used by a different ID here
 *   stops the import before anything is written
 * - scheduled tasks (3h), matched by command: the old app's enabled flag,
 *   schedule and timezone are copied onto tasks registered here. Tasks not
 *   ported yet are not created, `asp:backfill` always stays disabled until
 *   cutover (plan section 2, rule 6), and the listings audit keeps its
 *   later time here (SHIFTED_UNTIL_CUTOVER). Run history is not copied
 * - tools and tasks of a cutover group that has moved are left alone: the
 *   old app switches them off when they move (decision 2026-12-07)
 * - renamed tools (decision D7): the old slug's order, active flag and
 *   `tool.<slug>` grants move to the new slug. The old permission is not
 *   created here
 * It reports counts only, never names, emails or other values.
 */
final class PlatformImporter
{
    /** Copied from the old app. Password hashes and remember tokens are not: sign-in is Google only (decision 2026-11-27). */
    private const USER_COLUMNS = ['id', 'name', 'email', 'google_id', 'email_verified_at', 'disabled_at', 'created_at', 'updated_at'];

    private const CHUNK = 1000;

    /** Old slug => new slug, for tools renamed in this app (decision D7). */
    public const RENAMED_TOOLS = ['stockout' => 'biosteel-shopify-lost-sales'];

    /**
     * Task => the task whose enabled flag it takes, whatever the old app says
     * of its own. The old app left the snapshot clean-up off while the sweep
     * ran, so its table grew without limit (decision 2026-12-01).
     */
    public const ENABLED_WITH = ['stockout:prune-snapshots' => 'stockout:snapshot-inventory'];

    private const ACTIVITY_COLUMNS = ['id', 'log_name', 'description', 'subject_type', 'event', 'subject_id', 'causer_type', 'causer_id', 'properties', 'batch_uuid', 'created_at', 'updated_at'];

    /** @var array<string, array{read: int, new: int, updated: int, removed: int}> */
    private array $report = [];

    public function __construct(
        private readonly ConnectionInterface $legacy,
        private readonly ConnectionInterface $db,
        private readonly int $pauseMilliseconds = 250,
    ) {}

    public static function make(): self
    {
        return new self(DB::connection('legacy'), DB::connection());
    }

    /**
     * @return array<string, array{read: int, new: int, updated: int, removed: int}> per table
     *
     * @throws RuntimeException when an old user clashes with a different person here
     */
    public function run(bool $dryRun = false): array
    {
        $this->report = [];
        $this->refuseClashes();
        $this->refuseTeamClashes();

        $this->db->beginTransaction();

        try {
            $roles = $this->importByName('roles');
            $permissions = $this->importByName('permissions');
            $this->importDomains();
            $userIds = $this->importUsers();
            $this->importGrants('model_has_roles', 'role_id', $roles, $userIds);
            $this->importGrants('model_has_permissions', 'permission_id', $permissions, $userIds);
            $this->importRolePermissions($roles, $permissions);
            $this->importActivityLog();
            $this->importToolOrder();
            $teamIds = $this->importTeams();
            $this->importTeamMembers($teamIds, $userIds);
            $this->importScheduledTasks();
        } catch (\Throwable $e) {
            $this->db->rollBack();

            throw $e;
        }

        $dryRun ? $this->db->rollBack() : $this->db->commit();

        app(PermissionRegistrar::class)->forgetCachedPermissions();

        return $this->report;
    }

    /**
     * Users keep their IDs, so stop before writing anything, and name the
     * IDs, if an old user would overwrite a different person here:
     * - their email belongs to a different ID here, or
     * - their ID belongs to someone with a different email and a different
     *   Google ID (Google sign-in creates users here before cutover, so a
     *   new user can take an ID the old app hands out later). A changed
     *   email alone is the same person and is updated.
     */
    private function refuseClashes(): void
    {
        $clashes = [];

        $this->legacy->table('users')->select(['id', 'email', 'google_id'])->orderBy('id')->chunk(self::CHUNK, function ($rows) use (&$clashes) {
            $byEmail = $this->db->table('users')->whereIn('email', $rows->pluck('email'))->pluck('id', 'email');
            $byId = $this->db->table('users')->whereIn('id', $rows->pluck('id'))->get(['id', 'email', 'google_id'])->keyBy('id');

            foreach ($rows as $row) {
                if (isset($byEmail[$row->email]) && (int) $byEmail[$row->email] !== (int) $row->id) {
                    $clashes[] = "old user {$row->id} matches new user {$byEmail[$row->email]}";
                }

                $here = $byId[$row->id] ?? null;
                if ($here && strcasecmp($here->email, $row->email) !== 0 && $here->google_id !== $row->google_id) {
                    $clashes[] = "old user {$row->id} has the ID of a different user here";
                }
            }
        });

        if ($clashes !== []) {
            throw new RuntimeException('These old users clash with different users here, so nothing was imported: '.implode('; ', $clashes).'.');
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
        if ($table === 'permissions') {
            $old->each(fn ($row) => $row->name = $this->renamedPermission($row->name));
        }
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

    private function renamedPermission(string $name): string
    {
        foreach (self::RENAMED_TOOLS as $from => $to) {
            if ($name === "tool.{$from}") {
                return "tool.{$to}";
            }
        }

        return $name;
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
                $rows->map(fn ($row) => (array) $row + ['password' => null, 'remember_token' => null])->all(),
                ['id'],
                [...array_values(array_diff(self::USER_COLUMNS, ['id'])), 'password', 'remember_token'],
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

    private function importActivityLog(): void
    {
        $report = ['read' => 0, 'new' => 0, 'updated' => 0, 'removed' => 0];
        $first = true;

        $this->legacy->table('activity_log')->select(self::ACTIVITY_COLUMNS)->orderBy('id')->chunk(self::CHUNK, function ($rows) use (&$report, &$first) {
            if (! $first) {
                usleep($this->pauseMilliseconds * 1000);
            }
            $first = false;

            $existing = $this->db->table('activity_log')->whereIn('legacy_id', $rows->pluck('id'))->count();
            $this->db->table('activity_log')->upsert(
                $rows->map(function ($row) {
                    $values = (array) $row;
                    $values['legacy_id'] = $values['id'];
                    unset($values['id']);

                    return $values;
                })->all(),
                ['legacy_id'],
                array_values(array_diff(self::ACTIVITY_COLUMNS, ['id'])),
            );

            $report['read'] += $rows->count();
            $report['updated'] += $existing;
            $report['new'] += $rows->count() - $existing;
        });

        $this->report['activity_log'] = $report;
    }

    private function refuseTeamClashes(): void
    {
        $old = $this->legacy->table('teams')->get(['id', 'name']);
        $here = $this->db->table('teams')->whereIn('name', $old->pluck('name'))->pluck('id', 'name');
        $clashes = $old->filter(fn ($row) => isset($here[$row->name]) && (int) $here[$row->name] !== (int) $row->id)
            ->map(fn ($row) => "old team {$row->id} matches new team {$here[$row->name]}");

        if ($clashes->isNotEmpty()) {
            throw new RuntimeException('These old teams have a name that a different team already has here, so nothing was imported: '.$clashes->implode('; ').'.');
        }
    }

    /** @return list<int> the imported team IDs */
    private function importTeams(): array
    {
        $old = $this->legacy->table('teams')->orderBy('id')->get(['id', 'name', 'description', 'created_at', 'updated_at']);
        $existing = $this->db->table('teams')->whereIn('id', $old->pluck('id'))->count();

        if ($old->isNotEmpty()) {
            $this->db->table('teams')->upsert($old->map(fn ($row) => (array) $row)->all(), ['id'], ['name', 'description', 'created_at', 'updated_at']);
        }

        $this->report['teams'] = ['read' => $old->count(), 'new' => $old->count() - $existing, 'updated' => $existing, 'removed' => 0];

        return $old->pluck('id')->map(fn ($id) => (int) $id)->all();
    }

    /**
     * Each imported team's members, brought in line with the old app.
     *
     * @param  list<int>  $teamIds
     * @param  list<int>  $userIds
     */
    private function importTeamMembers(array $teamIds, array $userIds): void
    {
        $users = array_flip($userIds);
        $rows = $this->legacy->table('team_user')->whereIn('team_id', $teamIds)->get(['team_id', 'user_id', 'created_at', 'updated_at'])
            ->filter(fn ($row) => isset($users[(int) $row->user_id]))
            ->map(fn ($row) => ['team_id' => (int) $row->team_id, 'user_id' => (int) $row->user_id, 'created_at' => $row->created_at, 'updated_at' => $row->updated_at]);

        $key = fn (array $row) => $row['team_id'].'|'.$row['user_id'];
        $wanted = $rows->keyBy($key);
        $before = $this->db->table('team_user')->whereIn('team_id', $teamIds)->get(['id', 'team_id', 'user_id'])
            ->map(fn ($row) => ['id' => (int) $row->id, 'team_id' => (int) $row->team_id, 'user_id' => (int) $row->user_id])
            ->keyBy($key);

        // Change only what differs, so unchanged memberships keep their rows.
        $this->db->table('team_user')->whereIn('id', $before->diffKeys($wanted)->pluck('id'))->delete();
        foreach ($wanted->diffKeys($before)->values()->chunk(self::CHUNK) as $chunk) {
            $this->db->table('team_user')->insert($chunk->values()->all());
        }

        $this->report['team_user'] = [
            'read' => $rows->count(),
            'new' => $wanted->diffKeys($before)->count(),
            'updated' => 0,
            'removed' => $before->diffKeys($wanted)->count(),
        ];
    }

    /** Commands that stay disabled here whatever the old app says (plan section 2, rule 6). */
    public const DISABLED_UNTIL_CUTOVER = ['asp:backfill'];

    /**
     * Command => the schedule it keeps here until cutover, whatever the old
     * app's. Both apps share one SP-API rate limit, so this app's listings
     * audit runs after the old app's 08:00 run (decision 2026-12-06). At
     * cutover it goes back to 08:00.
     */
    public const SHIFTED_UNTIL_CUTOVER = ['listings:audit-run' => '30 8 * * *'];

    private function importScheduledTasks(): void
    {
        $old = $this->legacy->table('scheduled_tasks')->get(['command', 'enabled', 'cron_expression', 'timezone']);
        $oldEnabled = $old->mapWithKeys(fn ($row) => [$row->command => (bool) $row->enabled]);
        $updated = 0;

        foreach ($old as $row) {
            // A group that has moved runs its tasks here now; the old app's
            // (switched off at the move) no longer apply (decision 2026-12-07).
            if (($group = Cutover::groupForTask($row->command)) !== null && Cutover::hasMoved($group)) {
                continue;
            }
            $enabled = isset(self::ENABLED_WITH[$row->command]) ? (bool) $oldEnabled->get(self::ENABLED_WITH[$row->command], false) : (bool) $row->enabled;
            $values = [
                // Matched on the command's name: the ASP backfill is "asp:backfill CP" and "asp:backfill BS".
                'enabled' => $enabled && ! in_array(strtok($row->command, ' '), self::DISABLED_UNTIL_CUTOVER, true),
                'cron_expression' => self::SHIFTED_UNTIL_CUTOVER[$row->command] ?? $row->cron_expression,
                'timezone' => $row->timezone,
            ];
            $here = $this->db->table('scheduled_tasks')->where('command', $row->command)->first(['enabled', 'cron_expression', 'timezone']);

            if ($here && ((bool) $here->enabled !== $values['enabled'] || $here->cron_expression !== $values['cron_expression'] || $here->timezone !== $values['timezone'])) {
                $this->db->table('scheduled_tasks')->where('command', $row->command)->update($values + ['updated_at' => now()]);
                $updated++;
            }
        }

        $this->report['scheduled_tasks'] = ['read' => $old->count(), 'new' => 0, 'updated' => $updated, 'removed' => 0];
    }

    private function importToolOrder(): void
    {
        $old = $this->legacy->table('tools')->get(['slug', 'active', 'sort_order']);
        $updated = 0;

        foreach ($old as $row) {
            $slug = self::RENAMED_TOOLS[$row->slug] ?? $row->slug;
            // The old app switches a tool off when it moves here; that must not switch it off here too.
            if (($group = Cutover::groupForTool($slug)) !== null && Cutover::hasMoved($group)) {
                continue;
            }
            $updated += $this->db->table('tools')->where('slug', $slug)
                ->where(fn ($query) => $query->where('active', '!=', (bool) $row->active)->orWhere('sort_order', '!=', (int) $row->sort_order))
                ->update(['active' => (bool) $row->active, 'sort_order' => (int) $row->sort_order, 'updated_at' => now()]);
        }

        $this->report['tools'] = ['read' => $old->count(), 'new' => 0, 'updated' => $updated, 'removed' => 0];
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
