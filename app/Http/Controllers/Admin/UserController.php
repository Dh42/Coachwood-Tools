<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Team;
use App\Models\Tool;
use App\Models\User;
use App\Notifications\GoogleSignInInvitationNotification;
use App\Support\GoogleWorkspace;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
use Throwable;

/**
 * Admin > Users, ported from the old app. Inviting is Google only (decision
 * 2026-11-27): only company-domain addresses can be invited, and every
 * invitation is the "Sign in with Google" email; the old password link is
 * gone. Fixes (docs/found-issues.md):
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

    public function create(): View
    {
        return view('admin.users.create', [
            'tools' => Tool::query()->ordered()->get(),
            'teams' => Team::orderBy('name')->get(),
            'domains' => GoogleWorkspace::allowedDomains(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => [
                'required', 'email', 'max:255', 'unique:users,email',
                function (string $attribute, mixed $value, \Closure $fail) {
                    if (! GoogleWorkspace::isCompanyDomain((string) $value)) {
                        $fail('Only addresses on a company domain can be invited: they sign in with Google. See Admin > Google Sign-In.');
                    }
                },
            ],
            'role' => ['required', Rule::in(['admin', 'user'])],
            'tools' => ['array'],
            'tools.*' => ['string'],
            'teams' => ['array'],
            'teams.*' => ['integer', 'exists:teams,id'],
        ]);

        $user = User::create([
            'name' => $validated['name'],
            'email' => $validated['email'],
            'password' => null,
        ]);

        $user->assignRole($validated['role']);

        $tools = Tool::query()->whereIn('slug', $validated['tools'] ?? [])->get();
        $user->givePermissionTo($tools->map(fn (Tool $tool) => $tool->permissionName())->all());

        $user->teams()->sync($validated['teams'] ?? []);

        $sendError = $this->sendInvitation($user);

        activity('admin')
            ->causedBy($request->user())
            ->performedOn($user)
            ->log('User invited');

        if ($sendError !== null) {
            return redirect()->route('admin.users.index')->with(
                'error',
                "{$user->email} was created, but the invitation email failed to send: {$sendError}. Fix the issue, then use \"Resend invite\" on their row — the account already exists, so filling in this form again will say the email is taken."
            );
        }

        return redirect()->route('admin.users.index')->with('status', 'Invitation sent.');
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

    public function resendInvitation(Request $request, User $user): RedirectResponse
    {
        abort_if(! $user->isPending(), 403);

        if (! GoogleWorkspace::isCompanyDomain($user->email)) {
            return redirect()->route('admin.users.index')->with('error', "{$user->email} isn't on a company domain, so they can't sign in with Google. Nothing was sent.");
        }

        $sendError = $this->sendInvitation($user);

        activity('admin')
            ->causedBy($request->user())
            ->performedOn($user)
            ->log('Invitation resent');

        if ($sendError !== null) {
            return redirect()->route('admin.users.index')->with('error', "Invitation to {$user->email} failed to send: {$sendError}");
        }

        return redirect()->route('admin.users.index')->with('status', 'Invitation resent.');
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

    /**
     * Returns null on success, or a human-readable message on failure —
     * never throws. Sending goes through the Gmail API transport, which
     * throws for anything from "no Gmail account connected yet" to a
     * transient Google error; letting that propagate turned into a raw 500
     * with the user already created (ported from the old app).
     */
    private function sendInvitation(User $user): ?string
    {
        try {
            $user->notify(new GoogleSignInInvitationNotification);

            return null;
        } catch (Throwable $e) {
            Log::error('User invitation email failed to send', ['user_id' => $user->id, 'message' => $e->getMessage()]);

            return $e->getMessage();
        }
    }
}
