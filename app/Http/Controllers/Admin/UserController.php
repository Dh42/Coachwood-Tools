<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Team;
use App\Models\Tool;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/**
 * Admin > Users, ported from the old app. Inviting people (create, store,
 * resendInvitation) comes with mail in 3g. Fixes (docs/found-issues.md):
 * - 6: the form lists every tool, inactive ones marked, and saving changes
 *   only `tool.*` permissions, so switching a tool back on restores access
 *   and other permissions are never dropped (Lesley, 2026-11-28)
 * - 7: an admin can't deactivate or demote their own account
 */
class UserController extends Controller
{
    public function index(): View
    {
        return view('admin.users.index', [
            'users' => User::query()->with('roles')->orderBy('name')->get(),
        ]);
    }

    public function edit(User $user): View
    {
        $tools = Tool::query()->ordered()->get();
        $userPermissions = $user->getDirectPermissions()->pluck('name')->all();

        return view('admin.users.edit', [
            'user' => $user,
            'tools' => $tools,
            'teams' => Team::orderBy('name')->get(),
            'userToolSlugs' => $tools->filter(
                fn (Tool $tool) => in_array($tool->permissionName(), $userPermissions)
            )->pluck('slug')->all(),
            'userTeamIds' => $user->teams()->pluck('teams.id')->all(),
            'userRole' => $user->getRoleNames()->first(),
        ]);
    }

    public function update(Request $request, User $user): RedirectResponse
    {
        $validated = $request->validate([
            'role' => ['required', Rule::in(['admin', 'user'])],
            'tools' => ['array'],
            'tools.*' => ['string'],
            'teams' => ['array'],
            'teams.*' => ['integer', 'exists:teams,id'],
        ]);

        if ($user->is($request->user()) && $validated['role'] !== 'admin') {
            return back()->withErrors(['role' => 'You can\'t remove your own admin role. Ask another admin.'])->withInput();
        }

        $changes = [];

        $oldRole = $user->getRoleNames()->first();
        if ($oldRole !== $validated['role']) {
            $user->syncRoles([$validated['role']]);
            $changes[] = "role changed from {$oldRole} to {$validated['role']}";
        }

        $tools = Tool::query()->whereIn('slug', $validated['tools'] ?? [])->get();
        $newPermissionNames = $tools->map(fn (Tool $tool) => $tool->permissionName())->sort()->values()->all();
        $direct = $user->getDirectPermissions()->pluck('name');
        $oldPermissionNames = $direct->filter(fn (string $name) => str_starts_with($name, 'tool.'))->sort()->values()->all();
        $otherPermissionNames = $direct->reject(fn (string $name) => str_starts_with($name, 'tool.'))->values()->all();

        if ($oldPermissionNames !== $newPermissionNames) {
            $user->syncPermissions([...$otherPermissionNames, ...$newPermissionNames]);
            $changes[] = 'tool access changed';
        }

        $oldTeamIds = $user->teams()->pluck('teams.id')->sort()->values()->all();
        $newTeamIds = collect($validated['teams'] ?? [])->map(fn ($id) => (int) $id)->sort()->values()->all();

        if ($oldTeamIds !== $newTeamIds) {
            $user->teams()->sync($newTeamIds);
            $changes[] = 'team membership changed';
        }

        if ($changes !== []) {
            activity('admin')
                ->causedBy($request->user())
                ->performedOn($user)
                ->withProperties(['changes' => $changes])
                ->log('User updated');
        }

        return redirect()->route('admin.users.edit', $user)->with('status', 'User updated.');
    }

    public function deactivate(Request $request, User $user): RedirectResponse
    {
        abort_if($user->is($request->user()), 403);

        $user->forceFill(['disabled_at' => now()])->save();

        DB::table('sessions')->where('user_id', $user->id)->delete();

        activity('admin')
            ->causedBy($request->user())
            ->performedOn($user)
            ->log('Account deactivated');

        return redirect()->route('admin.users.index')->with('status', 'Account deactivated.');
    }

    public function activate(Request $request, User $user): RedirectResponse
    {
        $user->forceFill(['disabled_at' => null])->save();

        activity('admin')
            ->causedBy($request->user())
            ->performedOn($user)
            ->log('Account activated');

        return redirect()->route('admin.users.index')->with('status', 'Account activated.');
    }

    /**
     * Deactivation (disabled_at) is the normal, reversible way to remove
     * someone's access and is what every other part of this app assumes —
     * this is a genuine hard delete, only reachable once an account is
     * already deactivated, so it's never a one-click accident on someone
     * still using the tool. It's safe at the DB level (every user-attributed
     * column across the app is a nullable, nullOnDelete FK — nothing else
     * cascades or throws), but it does permanently blank out "who did this"
     * on that person's historical PO overrides, listing edits, invitations,
     * etc. — the confirm dialog in the view says so explicitly.
     */
    public function destroy(Request $request, User $user): RedirectResponse
    {
        abort_if($user->is($request->user()), 403);
        abort_if(! $user->isDisabled(), 403);

        activity('admin')
            ->causedBy($request->user())
            ->performedOn($user)
            ->withProperties(['email' => $user->email])
            ->log('User deleted');

        DB::table('sessions')->where('user_id', $user->id)->delete();
        $user->delete();

        return redirect()->route('admin.users.index')->with('status', 'User deleted.');
    }
}
