<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Tool;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\View\View;
use Spatie\Permission\Models\Permission;

/**
 * Admin > Tools. Ported from the old app; the only change is the new
 * `group` field (and its suggestions on the forms).
 */
class ToolController extends Controller
{
    public function index(): View
    {
        return view('admin.tools.index', [
            'tools' => Tool::query()->ordered()->get(),
        ]);
    }

    public function create(): View
    {
        return view('admin.tools.create', ['tool' => new Tool(['active' => true, 'sort_order' => 0]), 'groups' => $this->groups()]);
    }

    public function store(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'name' => [
                'required', 'string', 'max:255',
                function ($attribute, $value, $fail) {
                    if (Tool::where('slug', Str::slug($value))->exists()) {
                        $fail('A tool with this name already exists.');
                    }
                },
            ],
            'description' => ['nullable', 'string', 'max:1000'],
            'group' => ['nullable', 'string', 'max:255'],
            'icon' => ['nullable', 'string', 'max:255'],
            'url' => ['nullable', 'string', 'max:255', 'unique:tools,url'],
            'active' => ['boolean'],
            'sort_order' => ['integer'],
        ]);

        $validated['slug'] = Str::slug($validated['name']);
        $validated['active'] = $request->boolean('active');
        $validated['sort_order'] = $validated['sort_order'] ?? 0;

        $tool = Tool::create($validated);

        Permission::findOrCreate($tool->permissionName(), 'web');

        activity('admin')
            ->causedBy($request->user())
            ->performedOn($tool)
            ->log('Tool created');

        return redirect()->route('admin.tools.index')->with('status', 'Tool created.');
    }

    public function edit(Tool $tool): View
    {
        return view('admin.tools.edit', ['tool' => $tool, 'groups' => $this->groups()]);
    }

    /** @return list<string> the groups already in use, offered as suggestions */
    private function groups(): array
    {
        return Tool::query()->whereNotNull('group')->distinct()->orderBy('group')->pluck('group')->all();
    }

    public function update(Request $request, Tool $tool): RedirectResponse
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:1000'],
            'group' => ['nullable', 'string', 'max:255'],
            'icon' => ['nullable', 'string', 'max:255'],
            'url' => ['nullable', 'string', 'max:255', 'unique:tools,url,'.$tool->id],
            'active' => ['boolean'],
            'sort_order' => ['integer'],
        ]);

        $validated['active'] = $request->boolean('active');

        $tool->update($validated);

        activity('admin')
            ->causedBy($request->user())
            ->performedOn($tool)
            ->log('Tool updated');

        return redirect()->route('admin.tools.index')->with('status', 'Tool updated.');
    }

    /**
     * Persists a full drag-and-drop reorder from the Tools index page —
     * `order` is every tool's id, already in the new top-to-bottom order;
     * sort_order is just set to each id's position in that list. Not a
     * partial reorder: the drag UI always sends the whole visible table, so
     * this never needs to merge against tools the request doesn't mention.
     */
    public function reorder(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'order' => ['required', 'array', 'min:1'],
            'order.*' => ['integer', 'distinct', 'exists:tools,id'],
        ]);

        DB::transaction(function () use ($validated) {
            foreach ($validated['order'] as $position => $toolId) {
                Tool::where('id', $toolId)->update(['sort_order' => $position]);
            }
        });

        activity('admin')
            ->causedBy($request->user())
            ->withProperties(['order' => $validated['order']])
            ->log('Tools reordered');

        return response()->json(['success' => true]);
    }

    public function destroy(Request $request, Tool $tool): RedirectResponse
    {
        activity('admin')
            ->causedBy($request->user())
            ->withProperties(['name' => $tool->name, 'slug' => $tool->slug])
            ->log('Tool deleted');

        // Deleting through the Eloquent model (not a raw query) fires
        // spatie's cache-flush event, and the permission's cascadeOnDelete
        // foreign keys clean up any role/user grants automatically.
        Permission::where('name', $tool->permissionName())->first()?->delete();

        $tool->delete();

        return redirect()->route('admin.tools.index')->with('status', 'Tool deleted.');
    }
}
