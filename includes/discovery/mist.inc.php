<?php

/**
 * Mist Discovery
 *
 * Discovers Mist organizations and their access points as devices.
 * This runs as a global discovery (not per-device).
 */

use App\Actions\Device\ValidateDeviceAndCreate;
use App\Facades\LibrenmsConfig;
use App\Models\Device;
use App\Models\MistOrg;
use Illuminate\Support\Facades\Log;
use LibreNMS\Enum\Severity;
use LibreNMS\Polling\ConnectivityHelper;

if (! LibrenmsConfig::get('mist.enabled')) {
    return;
}

$mistOrgs = MistOrg::where('enabled', true)->get();

foreach ($mistOrgs as $mistOrg) {
    try {
        // Create or update org device
        $orgDevice = Device::where('os', 'mist-org')
            ->where('sysObjectID', $mistOrg->org_id)
            ->first();

        if (! $orgDevice) {
            $orgDevice = new Device([
                'hostname' => 'mist-org-' . $mistOrg->org_id,
                'sysName' => $mistOrg->name . ' (Mist Org)',
                'sysObjectID' => $mistOrg->org_id,
                'os' => 'mist-org',
                'snmp_disable' => true,
                'status' => true,
                'status_reason' => '',
            ]);

            if ((new ValidateDeviceAndCreate($orgDevice, force: true))->execute()) {
                // Refresh device cache and save attributes after device is created
                \DeviceCache::setPrimary($orgDevice->device_id);
                $orgDevice = \DeviceCache::getPrimary();
                $orgDevice->setAttrib('mist.org_id', $mistOrg->org_id);
                $orgDevice->setAttrib('mist.api_url', $mistOrg->api_url);
                $orgDevice->setAttrib('mist.api_key', $mistOrg->api_key);
                Log::info("Created Mist org device: {$mistOrg->name}");
            }
        } else {
            // Update org device attributes
            $orgDevice->sysName = $mistOrg->name . ' (Mist Org)';
            $orgDevice->setAttrib('mist.org_id', $mistOrg->org_id);
            $orgDevice->setAttrib('mist.api_url', $mistOrg->api_url);
            $orgDevice->setAttrib('mist.api_key', $mistOrg->api_key);
            $orgDevice->save();
        }

        // Create API client using org config directly
        $baseUrl = rtrim($mistOrg->api_url, '/');
        $token = $mistOrg->api_key;
        $orgId = $mistOrg->org_id;
        $siteFilter = $mistOrg->getSiteIdsArray();

        $apiClient = \LibreNMS\Util\Http::client()
            ->baseUrl($baseUrl)
            ->withToken($token, 'Token')
            ->acceptJson()
            ->timeout(30);

        // Get sites
        $sites = [];
        try {
            $sitesResp = $apiClient->get("/api/v1/orgs/$orgId/sites", [
                'limit' => 1000,
                'page' => 1,
            ])->throw();
            $sites = $sitesResp->json() ?? [];
        } catch (\Throwable $e) {
            Log::warning("Mist discovery: failed fetching sites for org {$mistOrg->name}: " . $e->getMessage());
            continue;
        }

        // Filter sites if configured
        if (! empty($siteFilter)) {
            $sites = array_values(array_filter($sites, static function ($site) use ($siteFilter) {
                return isset($site['id']) && in_array($site['id'], $siteFilter, true);
            }));
        }

        // Discover APs for each site
        foreach ($sites as $site) {
            if (empty($site['id'])) {
                continue;
            }

            $siteId = $site['id'];
            $siteName = $site['name'] ?? $siteId;

            // Get devices (APs) for this site
            try {
                $devicesResp = $apiClient->get("/api/v1/sites/$siteId/devices")->throw();
                $devices = $devicesResp->json() ?? [];
            } catch (\Throwable $e) {
                Log::debug("Mist discovery: failed fetching devices for site $siteId: " . $e->getMessage());
                continue;
            }

            foreach ((array) $devices as $apData) {
                if (($apData['type'] ?? null) !== 'ap') {
                    continue;
                }

                $mac = strtolower((string) ($apData['mac'] ?? ''));
                if ($mac === '') {
                    continue;
                }

                // Use internal IP if available, otherwise use MAC-based hostname
                $apIp = $apData['ip'] ?? null;
                $apHostname = $apIp ?: ('mist-ap-' . str_replace(':', '-', $mac));

                // Check if AP device already exists
                $apDevice = Device::where('os', 'mist-ap')
                    ->where(function ($query) use ($mac, $apHostname, $apIp) {
                        $query->where('sysObjectID', $mac)
                            ->orWhere('hostname', $apHostname);
                        if ($apIp) {
                            $query->orWhere('ip', inet_pton($apIp));
                        }
                    })
                    ->first();

                if (! $apDevice) {
                    $apDevice = new Device([
                        'hostname' => $apHostname,
                        'sysName' => $apData['name'] ?? ($siteName . '-' . substr($mac, -4)),
                        'sysObjectID' => $mac,
                        'os' => 'mist-ap',
                        'ip' => $apIp ? inet_pton($apIp) : null,
                        'snmp_disable' => true,
                        'status' => true, // Will be updated by ping check if IP exists
                        'status_reason' => '',
                    ]);

                    if ((new ValidateDeviceAndCreate($apDevice, force: true))->execute()) {
                        // Refresh device cache and save attributes after device is created
                        \DeviceCache::setPrimary($apDevice->device_id);
                        $apDevice = \DeviceCache::getPrimary();
                        $apDevice->setAttrib('mist.org_id', $mistOrg->org_id);
                        $apDevice->setAttrib('mist.site_id', $siteId);
                        $apDevice->setAttrib('mist.mac', $mac);
                        $apDevice->setAttrib('mist.api_url', $mistOrg->api_url);
                        $apDevice->setAttrib('mist.api_key', $mistOrg->api_key);
                        Log::info("Created Mist AP device: {$apDevice->sysName} ({$mac})");
                    }
                } else {
                    // Update existing AP device
                    $apDevice->sysName = $apData['name'] ?? ($siteName . '-' . substr($mac, -4));
                    if ($apIp) {
                        $apDevice->ip = inet_pton($apIp);
                    }
                    $apDevice->setAttrib('mist.org_id', $mistOrg->org_id);
                    $apDevice->setAttrib('mist.site_id', $siteId);
                    $apDevice->setAttrib('mist.mac', $mac);
                    $apDevice->setAttrib('mist.api_url', $mistOrg->api_url);
                    $apDevice->setAttrib('mist.api_key', $mistOrg->api_key);
                    $apDevice->save();
                }
            }
        }
    } catch (\Throwable $e) {
        Log::error("Mist discovery error for org {$mistOrg->name}: " . $e->getMessage());
    }
}
