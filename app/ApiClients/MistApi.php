<?php

namespace App\ApiClients;

use App\Facades\LibrenmsConfig;
use App\Models\Device;
use App\Models\MistOrg;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use LibreNMS\Util\Http;

/**
 * MistApi
 *
 * Lightweight client wrapper for the Juniper Mist Cloud REST API.
 * Resolves API credentials from MistOrg by org_id so the correct token
 * is used when multiple Mist organizations are configured.
 */
class MistApi
{
    private ?PendingRequest $client = null;

    public function __construct(private readonly Device $device)
    {
    }

    public function isEnabled(): bool
    {
        if (! LibrenmsConfig::get('mist.enabled')) {
            return false;
        }

        $orgId = (string) $this->device->getAttrib('mist.org_id');
        if ($orgId === '') {
            return false;
        }

        $mistOrg = MistOrg::where('org_id', $orgId)->where('enabled', true)->first();
        if ($mistOrg) {
            return $mistOrg->api_url !== '' && $mistOrg->api_key !== '';
        }

        // Fallback: device-level credentials (e.g. legacy or single-org)
        $baseUrl = (string) $this->device->getAttrib('mist.api_url');
        $token = (string) $this->device->getAttrib('mist.api_key');

        return $baseUrl !== '' && $token !== '';
    }

    public function getClient(): PendingRequest
    {
        if ($this->client === null) {
            $orgId = (string) $this->device->getAttrib('mist.org_id');
            $mistOrg = MistOrg::where('org_id', $orgId)->where('enabled', true)->first();

            if ($mistOrg) {
                $baseUrl = rtrim($mistOrg->api_url, '/');
                $token = $mistOrg->api_key;
            } else {
                $baseUrl = rtrim((string) $this->device->getAttrib('mist.api_url'), '/');
                $token = (string) $this->device->getAttrib('mist.api_key');
            }

            $this->client = Http::client()
                ->baseUrl($baseUrl)
                ->withToken($token, 'Token')
                ->acceptJson()
                ->timeout(30);
        }

        return $this->client;
    }

    /**
     * Placeholder for a GET request helper.
     * Concrete endpoints will be wired once the Mist API paths are defined.
     */
    public function get(string $path, array $query = []): Response
    {
        return $this->getClient()->get($path, $query);
    }

    /**
     * Convenience accessor for configured site filter.
     *
     * @return string[] Empty array means \"all sites\".
     */
    public function getConfiguredSiteIds(): array
    {
        $raw = (string) $this->device->getAttrib('mist.site_ids');
        if ($raw === '') {
            return [];
        }

        // Allow comma and/or whitespace separated IDs
        $parts = preg_split('/[\s,]+/', $raw) ?: [];

        return array_values(array_filter(array_map('strval', $parts)));
    }

    public function getOrgId(): string
    {
        return (string) $this->device->getAttrib('mist.org_id');
    }
}

