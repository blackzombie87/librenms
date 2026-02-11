<?php

namespace App\Http\Controllers;

use App\Models\MistOrg;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;
use LibreNMS\Util\Http;

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

        // Validate Mist API connectivity and credentials
        $validationResult = $this->validateMistApi($validated['api_url'], $validated['api_key'], $validated['org_id']);
        if ($validationResult['error']) {
            return redirect()->route('mist-orgs.index')
                ->withInput()
                ->withErrors(['api_validation' => $validationResult['message']]);
        }

        // Handle checkbox
        $validated['enabled'] = $request->has('enabled');

        MistOrg::create($validated);

        $successMessage = __('Mist organization added successfully.');
        if ($validationResult['org_name']) {
            $successMessage .= ' ' . __('Organization name: :name', ['name' => $validationResult['org_name']]);
        }

        return redirect()->route('mist-orgs.index')
            ->with('success', $successMessage);
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

        // Validate Mist API connectivity and credentials
        $validationResult = $this->validateMistApi($validated['api_url'], $validated['api_key'], $validated['org_id']);
        if ($validationResult['error']) {
            return redirect()->route('mist-orgs.index')
                ->withInput()
                ->withErrors(['api_validation' => $validationResult['message']]);
        }

        // Handle checkbox
        $validated['enabled'] = $request->has('enabled');

        $mistOrg->update($validated);

        $successMessage = __('Mist organization updated successfully.');
        if ($validationResult['org_name']) {
            $successMessage .= ' ' . __('Organization name: :name', ['name' => $validationResult['org_name']]);
        }

        return redirect()->route('mist-orgs.index')
            ->with('success', $successMessage);
    }

    public function destroy(MistOrg $mistOrg): RedirectResponse
    {
        $mistOrg->delete();

        return redirect()->route('mist-orgs.index')
            ->with('success', __('Mist organization deleted successfully.'));
    }

    /**
     * Validate Mist API connectivity and credentials.
     *
     * @param  string  $apiUrl
     * @param  string  $apiKey
     * @param  string  $orgId
     * @return array{error: bool, message?: string, org_name?: string}
     */
    private function validateMistApi(string $apiUrl, string $apiKey, string $orgId): array
    {
        $baseUrl = rtrim($apiUrl, '/');

        // Step 1: Check general API reachability (should return 404)
        try {
            $reachabilityResponse = Http::client()
                ->timeout(10)
                ->get($baseUrl);

            // Mist API base URL should return 404 (not found) if reachable
            if ($reachabilityResponse->status() !== 404) {
                return [
                    'error' => true,
                    'message' => __('General connectivity to Mist API is not available. The API URL may be incorrect or unreachable.'),
                ];
            }
        } catch (\Exception $e) {
            return [
                'error' => true,
                'message' => __('General connectivity to Mist API is not available: :error', ['error' => $e->getMessage()]),
            ];
        }

        // Step 2: Validate API key by fetching org details
        try {
            $orgResponse = Http::client()
                ->baseUrl($baseUrl)
                ->withToken($apiKey, 'Token')
                ->acceptJson()
                ->timeout(10)
                ->get("/api/v1/orgs/$orgId");

            if ($orgResponse->successful()) {
                $orgData = $orgResponse->json();
                $orgName = $orgData['name'] ?? null;

                return [
                    'error' => false,
                    'org_name' => $orgName,
                ];
            } else {
                return [
                    'error' => true,
                    'message' => __('The entered API key or organization ID is incorrect. Please verify your credentials.'),
                ];
            }
        } catch (\Exception $e) {
            return [
                'error' => true,
                'message' => __('Failed to validate API key: :error', ['error' => $e->getMessage()]),
            ];
        }
    }
}
