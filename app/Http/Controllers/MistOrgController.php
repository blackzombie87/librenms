<?php

namespace App\Http\Controllers;

use App\Models\MistOrg;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class MistOrgController extends Controller
{
    public function index(): View
    {
        $orgs = MistOrg::orderBy('name')->get();

        return view('mist-orgs.index', [
            'orgs' => $orgs,
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'api_url' => 'required|url',
            'api_key' => 'required|string',
            'org_id' => 'required|string|max:36',
            'site_ids' => 'nullable|string',
            'enabled' => 'boolean',
        ]);

        // Handle checkbox
        $validated['enabled'] = $request->has('enabled');

        MistOrg::create($validated);

        return redirect()->route('mist-orgs.index')
            ->with('success', __('Mist organization added successfully.'));
    }

    public function update(Request $request, MistOrg $mistOrg): RedirectResponse
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'api_url' => 'required|url',
            'api_key' => 'required|string',
            'org_id' => 'required|string|max:36',
            'site_ids' => 'nullable|string',
            'enabled' => 'boolean',
        ]);

        // Handle checkbox
        $validated['enabled'] = $request->has('enabled');

        $mistOrg->update($validated);

        return redirect()->route('mist-orgs.index')
            ->with('success', __('Mist organization updated successfully.'));
    }

    public function destroy(MistOrg $mistOrg): RedirectResponse
    {
        $mistOrg->delete();

        return redirect()->route('mist-orgs.index')
            ->with('success', __('Mist organization deleted successfully.'));
    }
}
