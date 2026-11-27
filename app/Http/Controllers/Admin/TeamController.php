<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Team;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class TeamController extends Controller
{
    public function index(): View
    {
        return view('admin.teams.index', [
            'teams' => Team::query()->withCount('members')->orderBy('name')->get(),
        ]);
    }

    public function create(): View
    {
        return view('admin.teams.create');
    }

    public function store(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255', 'unique:teams,name'],
            'description' => ['nullable', 'string'],
        ]);

        $team = Team::create($validated);

        activity('admin')
            ->causedBy($request->user())
            ->performedOn($team)
            ->log('Team created');

        return redirect()->route('admin.teams.edit', $team)->with('status', 'Team created.');
    }

    public function edit(Team $team): View
    {
        $team->load('members');

        return view('admin.teams.edit', [
            'team' => $team,
            'availableUsers' => User::query()
                ->whereNotIn('id', $team->members->pluck('id'))
                ->orderBy('name')
                ->get(),
        ]);
    }

    public function update(Request $request, Team $team): RedirectResponse
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255', 'unique:teams,name,'.$team->id],
            'description' => ['nullable', 'string'],
        ]);

        $team->update($validated);

        activity('admin')
            ->causedBy($request->user())
            ->performedOn($team)
            ->log('Team updated');

        return redirect()->route('admin.teams.edit', $team)->with('status', 'Team updated.');
    }

    public function destroy(Request $request, Team $team): RedirectResponse
    {
        activity('admin')
            ->causedBy($request->user())
            ->withProperties(['name' => $team->name])
            ->log('Team deleted');

        $team->delete();

        return redirect()->route('admin.teams.index')->with('status', 'Team deleted.');
    }

    public function storeMember(Request $request, Team $team): RedirectResponse
    {
        $validated = $request->validate([
            'user_id' => ['required', 'exists:users,id'],
        ]);

        $team->members()->syncWithoutDetaching([$validated['user_id']]);

        activity('admin')
            ->causedBy($request->user())
            ->performedOn($team)
            ->withProperties(['member_id' => $validated['user_id']])
            ->log('Member added to team');

        return redirect()->route('admin.teams.edit', $team)->with('status', 'Member added.');
    }

    public function destroyMember(Request $request, Team $team, User $user): RedirectResponse
    {
        $team->members()->detach($user);

        activity('admin')
            ->causedBy($request->user())
            ->performedOn($team)
            ->withProperties(['member_id' => $user->id])
            ->log('Member removed from team');

        return redirect()->route('admin.teams.edit', $team)->with('status', 'Member removed.');
    }
}
