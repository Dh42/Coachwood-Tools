<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\GoogleWorkspaceDomain;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/**
 * Admin > Google Sign-In: the Workspace domains allowed to sign in
 * (App\Support\GoogleWorkspace). Ported from the old app unchanged.
 */
class GoogleDomainController extends Controller
{
    public function index(): View
    {
        return view('admin.google-domains.index', [
            'domains' => GoogleWorkspaceDomain::orderBy('domain')->get(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        // Normalized before validation so the uniqueness check and the
        // format check both operate on the same lowercase/trimmed value
        // that will actually be stored — sidesteps relying on the DB's
        // collation to catch a same-domain-different-case duplicate.
        $request->merge(['domain' => strtolower(trim((string) $request->input('domain')))]);

        $validated = $request->validate([
            'domain' => [
                'required', 'string', 'max:255',
                'regex:/^(?!-)[a-z0-9-]+(\.[a-z0-9-]+)+$/',
                Rule::unique('google_workspace_domains', 'domain'),
            ],
        ], [
            'domain.regex' => 'Enter a valid domain, e.g. coachwoodgroup.com.',
        ]);

        GoogleWorkspaceDomain::create($validated);

        activity('admin')
            ->causedBy($request->user())
            ->withProperties(['domain' => $validated['domain']])
            ->log('Google sign-in domain added');

        return redirect()->route('admin.google-domains.index')->with('status', 'Domain added.');
    }

    public function destroy(Request $request, GoogleWorkspaceDomain $googleWorkspaceDomain): RedirectResponse
    {
        $domain = $googleWorkspaceDomain->domain;
        $googleWorkspaceDomain->delete();

        activity('admin')
            ->causedBy($request->user())
            ->withProperties(['domain' => $domain])
            ->log('Google sign-in domain removed');

        return redirect()->route('admin.google-domains.index')->with('status', 'Domain removed.');
    }
}
