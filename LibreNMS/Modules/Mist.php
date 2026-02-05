<?php

namespace LibreNMS\Modules;

use App\ApiClients\MistApi;
use App\Facades\DeviceCache;
use App\Models\AccessPoint;
use App\Models\Device;
use Illuminate\Support\Facades\Log;
use LibreNMS\Interfaces\Data\DataStorageInterface;
use LibreNMS\Interfaces\Module;
use LibreNMS\OS;
use LibreNMS\Polling\ModuleStatus;
use LibreNMS\RRD\RrdDefinition;

/**
 * Mist.php
 *
 * Native Juniper Mist Cloud integration using the Mist REST API.
 *
 * Design:
 * - User creates a single dummy device and assigns OS \"mist\" to represent the org.
 * - When mist.enabled + API settings are configured, this module:
 *   - Pulls org/site/AP stats via MistApi
 *   - Creates/updates AccessPoint rows for APs (similar to wireless controllers)
 *   - Emits org-level RRDs for device counts and SLE percentages
 *
 * This is a first implementation and focuses on:
 * - Org-level counts and SLE metrics
 * - AP inventory + basic client/utilization data
 * Site-level RRDs and richer AP graphs can be added iteratively.
 */
class Mist implements Module
{
    public function dependencies(): array
    {
        return ['os'];
    }

    public function shouldDiscover(OS $os, ModuleStatus $status): bool
    {
        // Discovery is handled in poll() for now; keep this disabled.
        return false;
    }

    public function discover(OS $os): void
    {
        // no-op
    }

    public function shouldPoll(OS $os, ModuleStatus $status): bool
    {
        $device = $os->getDevice();

        // Mist is API-only; don't require SNMP to be enabled
        if (! $status->isEnabledAndDeviceUp($device, false)) {
            return false;
        }

        if ($device->os !== 'mist') {
            return false;
        }

        $api = new MistApi($device);

        return $api->isEnabled();
    }

    public function poll(OS $os, DataStorageInterface $datastore): void
    {
        $device = $os->getDevice();
        $api = new MistApi($device);

        if (! $api->isEnabled()) {
            Log::debug('Mist module: disabled or not fully configured');

            return;
        }

        $orgId = $api->getOrgId();
        $siteFilter = $api->getConfiguredSiteIds();

        // --- 1) Org-level stats ------------------------------------------------
        $orgDevices = null;
        $orgStats = null;
        $slePercentages = [];

        try {
            $orgDevicesResp = $api->get("/api/v1/orgs/$orgId/devices/summary")->throw();
            $orgDevices = $orgDevicesResp->json();
        } catch (\Throwable $e) {
            Log::warning('Mist module: failed fetching org devices summary: ' . $e->getMessage());
        }

        try {
            $orgStatsResp = $api->get("/api/v1/orgs/$orgId/stats")->throw();
            $orgStats = $orgStatsResp->json();
        } catch (\Throwable $e) {
            Log::warning('Mist module: failed fetching org stats: ' . $e->getMessage());
        }

        if (is_array($orgStats) && isset($orgStats['sle']) && is_array($orgStats['sle'])) {
            foreach ($orgStats['sle'] as $entry) {
                if (! isset($entry['path'], $entry['user_minutes']['total'], $entry['user_minutes']['ok'])) {
                    continue;
                }

                $path = strtolower(str_replace(' ', '_', $entry['path']));
                $total = (float) $entry['user_minutes']['total'];
                $ok = (float) $entry['user_minutes']['ok'];
                $slePercentages[$path] = $total > 0 ? ($ok / $total * 100.0) : 0.0;
            }
        }

        // Store org device counts (APs/switches/gateways/devices/sites)
        $orgCounts = [
            'aps' => $orgDevices['num_aps'] ?? 0,
            'aps_unassigned' => $orgDevices['num_unassigned_aps'] ?? 0,
            'switches' => $orgDevices['num_switches'] ?? 0,
            'switches_unassigned' => $orgDevices['num_unassigned_switches'] ?? 0,
            'gateways' => $orgDevices['num_gateways'] ?? 0,
            'gateways_unassigned' => $orgDevices['num_unassigned_gateways'] ?? 0,
            'mxedges' => $orgDevices['num_mxedges'] ?? 0,
            'sites' => $orgStats['num_sites'] ?? 0,
            'devices' => $orgStats['num_devices'] ?? 0,
            'inventory' => $orgStats['num_inventory'] ?? 0,
            'devices_connected' => $orgStats['num_devices_connected'] ?? 0,
            'devices_disconnected' => $orgStats['num_devices_disconnected'] ?? 0,
        ];

        $rrdDefOrg = RrdDefinition::make()
            ->addDataset('aps', 'GAUGE', 0)
            ->addDataset('aps_unassigned', 'GAUGE', 0)
            ->addDataset('switches', 'GAUGE', 0)
            ->addDataset('switches_unassigned', 'GAUGE', 0)
            ->addDataset('gateways', 'GAUGE', 0)
            ->addDataset('gateways_unassigned', 'GAUGE', 0)
            ->addDataset('mxedges', 'GAUGE', 0)
            ->addDataset('sites', 'GAUGE', 0)
            ->addDataset('devices', 'GAUGE', 0)
            ->addDataset('inventory', 'GAUGE', 0)
            ->addDataset('devices_connected', 'GAUGE', 0)
            ->addDataset('devices_disconnected', 'GAUGE', 0);

        $datastore->put($device->toArray(), 'mist-org-devices', [
            'rrd_name' => ['mist-org-devices'],
            'rrd_def' => $rrdDefOrg,
        ], $orgCounts);

        if (! empty($slePercentages)) {
            $rrdDefSle = RrdDefinition::make();
            foreach ($slePercentages as $path => $value) {
                $rrdDefSle->addDataset($path, 'GAUGE', 0, 100);
            }

            $datastore->put($device->toArray(), 'mist-org-sle', [
                'rrd_name' => ['mist-org-sle'],
                'rrd_def' => $rrdDefSle,
            ], $slePercentages);
        }

        // --- 2) Sites and APs --------------------------------------------------
        $sites = [];

        try {
            $sitesResp = $api->get("/api/v1/orgs/$orgId/sites", [
                'limit' => 1000,
                'page' => 1,
            ])->throw();
            $sites = $sitesResp->json() ?? [];
        } catch (\Throwable $e) {
            Log::warning('Mist module: failed fetching sites: ' . $e->getMessage());
        }

        if (! is_array($sites)) {
            $sites = [];
        }

        // Filter sites if a specific list is configured
        if (! empty($siteFilter)) {
            $sites = array_values(array_filter($sites, static function ($site) use ($siteFilter) {
                return isset($site['id']) && in_array($site['id'], $siteFilter, true);
            }));
        }

        // Existing APs on this dummy device
        DeviceCache::setPrimary($device->device_id);
        $dbAps = DeviceCache::getPrimary()->accessPoints->keyBy->getCompositeKey();

        $totalApCount = 0;
        $totalClientCount = 0;

        foreach ($sites as $site) {
            if (empty($site['id'])) {
                continue;
            }

            $siteId = $site['id'];
            $siteName = $site['name'] ?? $siteId;

            // Site stats (for client count per site etc.) – can be graphed later
            try {
                $siteStatsResp = $api->get("/api/v1/sites/$siteId/stats")->throw();
                $siteStats = $siteStatsResp->json() ?? [];
            } catch (\Throwable $e) {
                $siteStats = [];
                Log::debug("Mist module: failed fetching stats for site $siteId: " . $e->getMessage());
            }

            // Site devices (APs, switches, gateways)
            try {
                $devicesResp = $api->get("/api/v1/sites/$siteId/stats/devices")->throw();
                $devices = $devicesResp->json() ?? [];
            } catch (\Throwable $e) {
                Log::debug("Mist module: failed fetching devices stats for site $siteId: " . $e->getMessage());
                continue;
            }

            foreach ((array) $devices as $ap) {
                if (($ap['type'] ?? null) !== 'ap') {
                    continue;
                }

                $mac = strtolower((string) ($ap['mac'] ?? ''));
                if ($mac === '') {
                    continue;
                }

                $model = $ap['model'] ?? 'Mist AP';
                $serial = $ap['serial'] ?? '';
                $name = $ap['name'] ?? '';
                if ($name === '') {
                    $name = $siteName . '-' . substr($serial ?: $mac, -4);
                }

                $numClientsAp = (int) ($ap['num_clients'] ?? 0);

                $band24 = $ap['radio_stat']['band_24'] ?? [];
                $band5 = $ap['radio_stat']['band_5'] ?? [];

                // Some APs may not report RF details; default to 0 instead of NULL to satisfy DB constraints
                $channel = (int) ($band5['channel'] ?? $band24['channel'] ?? 0);
                $power = (int) ($band5['power'] ?? $band24['power'] ?? 0);
                $util = (int) ($band5['util_all'] ?? $band24['util_all'] ?? 0);

                $apModel = new AccessPoint([
                    'device_id' => $device->device_id,
                    'name' => $name,
                    'radio_number' => 0,
                    'type' => $model,
                    'mac_addr' => $mac,
                    'channel' => $channel,
                    'txpow' => $power,
                    'radioutil' => $util,
                    'numasoclients' => $numClientsAp,
                    'nummonclients' => 0,
                    'numactbssid' => $band5['num_wlans'] ?? 0,
                    'nummonbssid' => $band24['num_wlans'] ?? 0,
                    'interference' => 0,
                ]);

                $totalApCount++;
                $totalClientCount += $numClientsAp;

                $apKey = $apModel->getCompositeKey();
                if ($dbAps->has($apKey)) {
                    $dbAp = $dbAps->get($apKey);
                    $dbAp->fill($apModel->getAttributes());
                    $dbAp->deleted = 0;
                    $dbAp->save();
                    $dbAps->forget($apKey);
                } else {
                    DeviceCache::getPrimary()->accessPoints()->save($apModel);
                }

                // Per-AP RRD: clients + basic RF utilization (first band only for now)
                $rrdDefAp = RrdDefinition::make()
                    ->addDataset('clients', 'GAUGE', 0)
                    ->addDataset('band24_util', 'GAUGE', 0, 100)
                    ->addDataset('band5_util', 'GAUGE', 0, 100);

                $fieldsAp = [
                    'clients' => $numClientsAp,
                    'band24_util' => $band24['util_all'] ?? 0,
                    'band5_util' => $band5['util_all'] ?? 0,
                ];

                $datastore->put($device->toArray(), 'mist-ap', [
                    'name' => $name,
                    'mac' => $mac,
                    'site' => $siteName,
                    'rrd_name' => ['mist-ap', $name . '-' . $mac],
                    'rrd_def' => $rrdDefAp,
                ], $fieldsAp);
            }
        }

        // Mark APs that no longer exist in Mist as deleted
        $dbAps->each->update(['deleted' => 1]);

        // Optional: org-level AP/client totals from aggregated site data
        $rrdDefApSummary = RrdDefinition::make()
            ->addDataset('aps', 'GAUGE', 0)
            ->addDataset('clients', 'GAUGE', 0);

        $datastore->put($device->toArray(), 'mist-org-aps', [
            'rrd_name' => ['mist-org-aps'],
            'rrd_def' => $rrdDefApSummary,
        ], [
            'aps' => $totalApCount,
            'clients' => $totalClientCount,
        ]);
    }

    /**
     * @inheritDoc
     */
    public function dataExists(Device $device): bool
    {
        return $device->accessPoints()->exists();
    }

    /**
     * @inheritDoc
     */
    public function cleanup(Device $device): int
    {
        return $device->accessPoints()->delete();
    }

    /**
     * @inheritDoc
     */
    public function dump(Device $device, string $type): ?array
    {
        if ($type === 'poller') {
            return null;
        }

        return [
            'access_points' => $device->accessPoints()
                ->orderBy('name')
                ->orderBy('radio_number')
                ->get()
                ->map->makeHidden(['device_id', 'accesspoint_id', 'deleted']),
        ];
    }
}

