<?php

namespace App\ApiClients;

use App\Facades\LibrenmsConfig;
use App\Models\Device;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use LibreNMS\Util\Http;

/**
 * MistApi
 *
 * Lightweight client wrapper for the Juniper Mist Cloud REST API.
 * Actual endpoint usage will be added later; for now this only
 * encapsulates configuration and basic request helpers.
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

        $baseUrl = (string) $this->device->getAttrib('mist.api_url');
        $token = (string) $this->device->getAttrib('mist.api_key');
        $orgId = (string) $this->device->getAttrib('mist.org_id');

        return $baseUrl !== '' && $token !== '' && $orgId !== '';
    }

    public function getClient(): PendingRequest
    {
        if ($this->client === null) {
            $baseUrl = rtrim((string) $this->device->getAttrib('mist.api_url'), '/');
            $token = (string) $this->device->getAttrib('mist.api_key');

            $this->client = Http::client()
                ->baseUrl($baseUrl)
                ->withToken($token)
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

