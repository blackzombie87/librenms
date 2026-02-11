<?php

namespace LibreNMS\Modules;

use App\ApiClients\MistApi;
use App\Models\Device;
use App\Models\Link;
use App\Models\Mempool;
use App\Models\Port;
use App\Models\Processor;
use App\Models\Sensor;
use App\Models\SensorToStateIndex;
use App\Models\StateIndex;
use App\Models\StateTranslation;
use App\Models\WirelessSensor;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use LibreNMS\DB\SyncsModels;
use LibreNMS\Device\WirelessSensor as LegacyWirelessSensor;
use LibreNMS\Interfaces\Data\DataStorageInterface;
use LibreNMS\Interfaces\Module;
use LibreNMS\OS;
use LibreNMS\Polling\ModuleStatus;
use LibreNMS\RRD\RrdDefinition;
use LibreNMS\Util\Debug;

/**
 * MistAp Module
 *
 * Polls Mist Access Point devices via the Mist API.
 * Updates device metrics, ports (ethernet interfaces), and wireless sensors.
 */
class MistAp implements Module
{
    use SyncsModels;

    public function dependencies(): array
    {
        return ['os'];
    }

    public function shouldDiscover(OS $os, ModuleStatus $status): bool
    {
        return false; // Discovery handled by global mist.inc.php
    }

    public function discover(OS $os): void
    {
        // no-op
    }

    public function shouldPoll(OS $os, ModuleStatus $status): bool
    {
        $device = $os->getDevice();

        // Poll even when device is down so we can update status from API (connected/disconnected)
        if (! $status->isEnabled() || $device->os !== 'mist-ap') {
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
            Log::debug('MistAp module: disabled or not fully configured');

            return;
        }

        $orgId = $api->getOrgId();
        $siteId = (string) $device->getAttrib('mist.site_id');
        $mistDeviceId = (string) $device->getAttrib('mist.device_id');

        if ($siteId === '' || $mistDeviceId === '') {
            Log::warning("MistAp module: missing site_id or device_id for device {$device->hostname}");

            return;
        }

        try {
            // Fetch AP details and stats using Mist internal device ID
            $apResp = $api->get("/api/v1/sites/$siteId/devices/$mistDeviceId")->throw();
            $apData = $apResp->json();

            $statsResp = $api->get("/api/v1/sites/$siteId/stats/devices/$mistDeviceId")->throw();
            $apStats = $statsResp->json();

            if (Debug::isVerbose()) {
                Log::channel('stdout')->debug('[MistAp] AP device data: ' . json_encode($apData, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
                Log::channel('stdout')->debug('[MistAp] AP stats: ' . json_encode($apStats, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
            }

            // Set device up/down from Mist API status (connected vs disconnected)
            $this->updateDeviceStatus($device, $apStats);

            // Update device basic info (version etc. may come from apStats when connected)
            $device->hardware = $apData['model'] ?? $device->hardware;
            $device->version = $apStats['version'] ?? $apData['version'] ?? $device->version;
            $device->serial = $apData['serial'] ?? $device->serial;
            $device->uptime = $apStats['uptime'] ?? $apData['uptime'] ?? $device->uptime;

            // Keep management IP in sync when provided by the Mist API
            $apIp = $apStats['ip'] ?? null;
            if ($apIp) {
                // Device model mutator will convert this to binary for storage
                $device->ip = $apIp;
            }

            $device->sysDescr = ($apData['model'] ?? 'Mist AP') . ' - ' . ($apStats['version'] ?? $apData['version'] ?? 'Unknown');
            $device->save();

            // Native tables: processor, mempool, temperature (only when connected and data present)
            $this->updateProcessor($device, $apStats, $datastore);
            $this->updateMempool($device, $apStats, $datastore);
            $this->updateEnvSensors($device, $apStats, $datastore);

            // Update ports (ethernet interfaces)
            $this->updatePorts($device, $apData, $apStats, $datastore);

            // Create per-band radio ports with byte/packet counters
            $this->updateRadioPorts($device, $apStats, $datastore);

            // Update LLDP neighbors from lldp_stats
            $this->updateLldpLinks($device, $apStats);

            // Update wireless sensors
            $this->updateWirelessSensors($os, $device, $apData, $apStats, $datastore);

        } catch (\Throwable $e) {
            Log::warning("MistAp module: failed polling device {$device->hostname}: " . $e->getMessage());
        }
    }

    private function updatePorts(Device $device, array $apData, array $apStats, DataStorageInterface $datastore): void
    {
        // Mist AP stats commonly provide port stats under port_stat keyed by interface name (ex: eth0)
        $portStat = $apStats['port_stat'] ?? [];
        if (! is_array($portStat) || empty($portStat)) {
            return;
        }

        $ifIndex = 0;
        foreach ($portStat as $ifName => $stats) {
            if (! is_array($stats) || $ifName === '') {
                continue;
            }

            $ifOperUp = (bool) ($stats['up'] ?? false);
            $ifSpeed = (int) ($stats['speed'] ?? 0) * 1_000_000; // Mist reports Mbps, DB expects bps
            $fullDuplex = (bool) ($stats['full_duplex'] ?? false);

            // Find or create port
            $port = Port::firstOrNew([
                'device_id' => $device->device_id,
                'ifIndex' => $ifIndex,
            ], [
                'ifName' => $ifName,
                'ifDescr' => $ifName,
                'ifType' => 6, // ethernetCsmacd
            ]);

            $port->ifName = $ifName;
            $port->ifDescr = $ifName;
            $port->ifOperStatus = $ifOperUp ? 'up' : 'down';
            $port->ifAdminStatus = $ifOperUp ? 'up' : 'down';
            $port->ifSpeed = $ifSpeed;
            $port->ifDuplex = $fullDuplex ? 'fullDuplex' : 'halfDuplex';

            // Counters
            $port->ifInOctets = (int) ($stats['rx_bytes'] ?? 0);
            $port->ifOutOctets = (int) ($stats['tx_bytes'] ?? 0);
            $port->ifInUcastPkts = (int) ($stats['rx_pkts'] ?? $stats['rx_packets'] ?? 0);
            $port->ifOutUcastPkts = (int) ($stats['tx_pkts'] ?? $stats['tx_packets'] ?? 0);
            $port->ifInErrors = (int) ($stats['rx_errors'] ?? 0);
            $port->ifOutErrors = (int) ($stats['tx_errors'] ?? 0);
            $port->save();

            // Store RRD data using same format as includes/polling/ports.inc.php (port_id-based name, DERIVE, application 'ports')
            $rrdName = 'port-id' . $port->port_id;
            $rrdDef = RrdDefinition::make()
                ->addDataset('INOCTETS', 'DERIVE', 0, 12500000000)
                ->addDataset('OUTOCTETS', 'DERIVE', 0, 12500000000)
                ->addDataset('INERRORS', 'DERIVE', 0, 12500000000)
                ->addDataset('OUTERRORS', 'DERIVE', 0, 12500000000)
                ->addDataset('INUCASTPKTS', 'DERIVE', 0, 12500000000)
                ->addDataset('OUTUCASTPKTS', 'DERIVE', 0, 12500000000);

            $datastore->put($device->toArray(), 'ports', [
                'ifName' => $ifName,
                'ifAlias' => $port->ifAlias ?? '',
                'ifIndex' => $port->ifIndex,
                'port_descr_type' => $port->port_descr_type ?? 'ifName',
                'rrd_name' => $rrdName,
                'rrd_def' => $rrdDef,
            ], [
                'INOCTETS' => $port->ifInOctets,
                'OUTOCTETS' => $port->ifOutOctets,
                'INERRORS' => $port->ifInErrors,
                'OUTERRORS' => $port->ifOutErrors,
                'INUCASTPKTS' => $port->ifInUcastPkts,
                'OUTUCASTPKTS' => $port->ifOutUcastPkts,
            ]);

            $ifIndex++;
        }
    }

    /**
     * Create/update pseudo-ports for each wireless band (2.4 GHz / 5 GHz / 6 GHz / etc.) with tx/rx byte and packet counters.
     */
    private function updateRadioPorts(Device $device, array $apStats, DataStorageInterface $datastore): void
    {
        $radioStat = $apStats['radio_stat'] ?? [];
        if (! is_array($radioStat) || empty($radioStat)) {
            return;
        }

        // Map band keys to display labels
        $bandLabels = [
            'band_24' => '2.4 GHz Radio',
            'band_5' => '5 GHz Radio',
            'band_6' => '6 GHz Radio',
        ];

        $baseIfIndex = 100;
        $bandIndex = 0;

        // Iterate over all bands present in radio_stat (band_24, band_5, band_6, etc.)
        foreach ($radioStat as $bandKey => $radio) {
            if (! is_array($radio) || empty($radio)) {
                continue;
            }

            // Extract frequency from band key (e.g., 'band_24' -> '24', 'band_5' -> '5')
            $freq = preg_replace('/^band_/', '', $bandKey);
            $ifIndex = $baseIfIndex + $bandIndex++;
            $ifName = 'wlan' . $freq;
            $label = $bandLabels[$bandKey] ?? ($freq . ' GHz Radio');

            $port = Port::firstOrNew([
                'device_id' => $device->device_id,
                'ifIndex' => $ifIndex,
            ], [
                'ifName' => $ifName,
                'ifDescr' => $label,
                'ifType' => 71, // ieee80211
            ]);

            $port->ifName = $ifName;
            $port->ifDescr = $label;
            $port->ifType = 71;
            $port->ifOperStatus = 'up';
            $port->ifAdminStatus = 'up';
            $port->ifSpeed = 0;

            $port->ifInOctets = (int) ($radio['rx_bytes'] ?? 0);
            $port->ifOutOctets = (int) ($radio['tx_bytes'] ?? 0);
            $port->ifInUcastPkts = (int) ($radio['rx_pkts'] ?? $radio['rx_packets'] ?? 0);
            $port->ifOutUcastPkts = (int) ($radio['tx_pkts'] ?? $radio['tx_packets'] ?? 0);
            $port->ifInErrors = 0;
            $port->ifOutErrors = 0;
            $port->save();

            $rrdName = 'port-id' . $port->port_id;
            $rrdDef = RrdDefinition::make()
                ->addDataset('INOCTETS', 'DERIVE', 0, 12500000000)
                ->addDataset('OUTOCTETS', 'DERIVE', 0, 12500000000)
                ->addDataset('INERRORS', 'DERIVE', 0, 12500000000)
                ->addDataset('OUTERRORS', 'DERIVE', 0, 12500000000)
                ->addDataset('INUCASTPKTS', 'DERIVE', 0, 12500000000)
                ->addDataset('OUTUCASTPKTS', 'DERIVE', 0, 12500000000);

            $datastore->put($device->toArray(), 'ports', [
                'ifName' => $ifName,
                'ifAlias' => $port->ifAlias ?? '',
                'ifIndex' => $port->ifIndex,
                'port_descr_type' => $port->port_descr_type ?? 'ifName',
                'rrd_name' => $rrdName,
                'rrd_def' => $rrdDef,
            ], [
                'INOCTETS' => $port->ifInOctets,
                'OUTOCTETS' => $port->ifOutOctets,
                'INERRORS' => $port->ifInErrors,
                'OUTERRORS' => $port->ifOutErrors,
                'INUCASTPKTS' => $port->ifInUcastPkts,
                'OUTUCASTPKTS' => $port->ifOutUcastPkts,
            ]);
        }
    }

    /**
     * Update LLDP neighbor links from Mist API lldp_stats (keyed by interface name, e.g. eth0).
     * Stores in the same links table used by discovery (protocol = lldp).
     */
    private function updateLldpLinks(Device $device, array $apStats): void
    {
        $lldpStats = $apStats['lldp_stats'] ?? [];
        if (! is_array($lldpStats) || empty($lldpStats)) {
            return;
        }

        foreach ($device->ports()->get() as $port) {
            $lldp = $lldpStats[$port->ifName] ?? null;

            // Remove existing LLDP links for this port; we'll re-add if we have current data
            $port->links()->where('protocol', 'lldp')->delete();

            if (! is_array($lldp) || empty($lldp)) {
                continue;
            }

            $remoteHostname = trim((string) ($lldp['system_name'] ?? ''));
            $remotePort = trim((string) ($lldp['port_id'] ?? $lldp['port_desc'] ?? ''));
            $remotePlatform = trim((string) ($lldp['system_desc'] ?? '')) ?: null;

            if ($remoteHostname === '' && $remotePort === '') {
                continue;
            }

            if ($remoteHostname === '') {
                $remoteHostname = (string) ($lldp['chassis_id'] ?? 'unknown');
            }

            $link = new Link;
            $link->local_port_id = $port->port_id;
            $link->local_device_id = $device->device_id;
            $link->protocol = 'lldp';
            $link->remote_hostname = $remoteHostname;
            $link->remote_port = $remotePort;
            $link->remote_platform = $remotePlatform;
            $link->remote_version = '';
            $link->remote_device_id = 0;
            $link->remote_port_id = null;
            $link->save();
        }
    }

    private function updateDeviceStatus(Device $device, array $apStats): void
    {
        $status = strtolower((string) ($apStats['status'] ?? ''));
        $device->status = ($status === 'connected') ? 1 : 0;
        $device->status_reason = $device->status ? '' : ($status !== '' ? $status : 'unknown');
    }

    private function updateProcessor(Device $device, array $apStats, DataStorageInterface $datastore): void
    {
        if (! array_key_exists('cpu_util', $apStats) || ! is_numeric($apStats['cpu_util'])) {
            return;
        }

        $usage = (int) $apStats['cpu_util'];
        $processor = $device->processors()->where('processor_index', '0')->where('processor_type', 'mist')->first();

        if (! $processor) {
            $processor = new Processor;
            $processor->processor_index = '0';
            $processor->processor_type = 'mist';
            $processor->processor_descr = 'CPU';
            $processor->processor_oid = '.1.0.mist.0';
            $processor->processor_precision = 1;
            $processor->processor_usage = $usage;
            $device->processors()->save($processor);
        } else {
            $processor->processor_usage = $usage;
            $processor->processor_descr = 'CPU';
            $processor->save();
        }

        $rrdDef = RrdDefinition::make()->addDataset('usage', 'GAUGE', -273, 1000);
        $datastore->put($device->toArray(), 'processors', [
            'processor_type' => 'mist',
            'processor_index' => '0',
            'rrd_name' => ['processor', 'mist', '0'],
            'rrd_def' => $rrdDef,
        ], ['usage' => $usage]);
    }

    private function updateMempool(Device $device, array $apStats, DataStorageInterface $datastore): void
    {
        $totalKb = isset($apStats['mem_total_kb']) && is_numeric($apStats['mem_total_kb']) ? (int) $apStats['mem_total_kb'] : null;
        $usedKb = isset($apStats['mem_used_kb']) && is_numeric($apStats['mem_used_kb']) ? (int) $apStats['mem_used_kb'] : null;
        if ($totalKb === null || $totalKb <= 0 || $usedKb === null) {
            return;
        }

        $total = $totalKb * 1024;
        $used = $usedKb * 1024;
        $free = $total - $used;

        $mempool = $device->mempools()->where('mempool_index', '0')->first();

        if (! $mempool) {
            $mempool = new Mempool;
            $mempool->mempool_index = '0';
            $mempool->mempool_type = 'memory';
            $mempool->mempool_class = 'system';
            $mempool->mempool_descr = 'Memory';
            $mempool->mempool_total = $total;
            $mempool->mempool_used = $used;
            $mempool->mempool_free = $free;
            $mempool->mempool_perc = $total > 0 ? round($used / $total * 100, 2) : 0;
            $device->mempools()->save($mempool);
        } else {
            $mempool->mempool_total = $total;
            $mempool->mempool_used = $used;
            $mempool->mempool_free = $free;
            $mempool->mempool_perc = $total > 0 ? round($used / $total * 100, 2) : 0;
            $mempool->save();
        }

        $rrdDef = RrdDefinition::make()
            ->addDataset('used', 'GAUGE', 0)
            ->addDataset('free', 'GAUGE', 0);
        $datastore->put($device->toArray(), 'mempool', [
            'mempool_type' => 'memory',
            'mempool_class' => 'system',
            'mempool_index' => '0',
            'rrd_name' => ['mempool', 'memory', 'system', '0'],
            'rrd_def' => $rrdDef,
        ], ['used' => $used, 'free' => $free]);
    }

    private function updateEnvSensors(Device $device, array $apStats, DataStorageInterface $datastore): void
    {
        $env = $apStats['env_stat'] ?? [];
        if (! is_array($env) || empty($env)) {
            return;
        }

        $sensors = [
            'cpu_temp' => ['index' => 'cpu', 'descr' => 'CPU Temperature'],
            'ambient_temp' => ['index' => 'ambient', 'descr' => 'Ambient Temperature'],
        ];

        foreach ($sensors as $key => $meta) {
            if (! array_key_exists($key, $env) || ! is_numeric($env[$key])) {
                continue;
            }
            $value = (float) $env[$key];
            $sensor = $device->sensors()
                ->where('sensor_class', 'temperature')
                ->where('sensor_type', 'mist')
                ->where('sensor_index', $meta['index'])
                ->first();

            if (! $sensor) {
                $sensor = new Sensor;
                $sensor->sensor_class = 'temperature';
                $sensor->sensor_type = 'mist';
                $sensor->sensor_index = $meta['index'];
                $sensor->poller_type = 'api';
                $sensor->sensor_oid = 'mist.env.' . $key;
                $sensor->sensor_descr = $meta['descr'];
                $sensor->sensor_divisor = 1;
                $sensor->sensor_multiplier = 1;
                $sensor->rrd_type = 'GAUGE';
                $device->sensors()->save($sensor);
            }

            $sensor->sensor_current = $value;
            $sensor->sensor_descr = $meta['descr'];
            $sensor->save();

            $rrdDef = RrdDefinition::make()->addDataset('sensor', 'GAUGE');
            $datastore->put($device->toArray(), 'sensor', [
                'sensor_class' => 'temperature',
                'sensor_type' => 'mist',
                'sensor_descr' => $meta['descr'],
                'sensor_index' => $meta['index'],
                'rrd_name' => ['sensor', 'temperature', 'mist', $meta['index']],
                'rrd_def' => $rrdDef,
            ], ['sensor' => $value]);
        }
    }

    private function updateWirelessSensors(OS $os, Device $device, array $apData, array $apStats, DataStorageInterface $datastore): void
    {
        $existingSensors = $device->wirelessSensors()->get()->keyBy(fn ($s) => $s->sensor_class . '_' . $s->sensor_index);
        $sensors = collect();

        // Client count
        $numClients = (int) ($apStats['num_clients'] ?? 0);
        $sensors->push((new LegacyWirelessSensor('clients', $device->device_id, [], 'mist', 'total', 'Total Clients', $numClients))->toModel());

        // Radio stats (present for connected APs) - iterate over all bands dynamically
        $radioStat = $apStats['radio_stat'] ?? [];
        $bandLabels = [
            'band_24' => '2.4 GHz',
            'band_5' => '5 GHz',
            'band_6' => '6 GHz',
        ];

        foreach ($radioStat as $bandKey => $radio) {
            if (! is_array($radio) || empty($radio)) {
                continue;
            }

            // Extract frequency from band key and create index prefix (e.g., 'band_24' -> 'band24', 'band_6' -> 'band6')
            $freq = preg_replace('/^band_/', '', $bandKey);
            $indexPrefix = 'band' . $freq;
            $label = $bandLabels[$bandKey] ?? ($freq . ' GHz');

            $this->addRadioSensors($sensors, $device, $label, $indexPrefix, $radio);
        }

        // Sync sensors and update RRD data
        $synced = $this->syncModels($device, 'wirelessSensors', $sensors, $existingSensors);

        // Update RRD for all sensors
        foreach ($device->wirelessSensors()->get() as $sensor) {
            $this->updateSensor($sensor, $os, $datastore);
        }
    }

    private function addRadioSensors(Collection $sensors, Device $device, string $label, string $indexPrefix, array $radio): void
    {
        // Per-band clients
        if (array_key_exists('num_clients', $radio)) {
            $clients = (int) ($radio['num_clients'] ?? 0);
            $sensors->push((new LegacyWirelessSensor('clients', $device->device_id, [], 'mist', $indexPrefix . '_clients', "$label Clients", $clients))->toModel());
        }

        // Channel
        if (isset($radio['channel']) && is_numeric($radio['channel'])) {
            $channel = (int) $radio['channel'];
            $sensors->push((new LegacyWirelessSensor('channel', $device->device_id, [], 'mist', $indexPrefix . '_channel', "$label Channel", $channel))->toModel());

            // Also expose channel as a state sensor so it is visible on the device overview page.
            // State sensors need a state_index and state_translations so the value is not "Unknown".
            $stateSensor = $device->sensors()
                ->where('sensor_class', 'state')
                ->whereIn('sensor_type', ['mist.wifi.channel', 'mist'])
                ->where('sensor_index', $indexPrefix . '_channel')
                ->first();

            if (! $stateSensor) {
                $stateSensor = new Sensor;
                $stateSensor->sensor_class = 'state';
                $stateSensor->sensor_type = 'mist.wifi.channel';
                $stateSensor->sensor_index = $indexPrefix . '_channel';
                $stateSensor->poller_type = 'api';
                $stateSensor->sensor_oid = 'mist.radio.' . $indexPrefix . '.channel';
                $stateSensor->sensor_descr = $label . ' Channel';
                $stateSensor->sensor_divisor = 1;
                $stateSensor->sensor_multiplier = 1;
                $stateSensor->rrd_type = 'GAUGE';
                $device->sensors()->save($stateSensor);
            } else {
                $stateSensor->sensor_type = 'mist.wifi.channel';
                $stateSensor->sensor_descr = $label . ' Channel';
            }

            $stateSensor->sensor_current = $channel;
            $stateSensor->save();

            // Ensure state_index and translation exist so the value displays (not "Unknown")
            $stateIndex = StateIndex::firstOrCreate(
                ['state_name' => 'mist.wifi.channel'],
                ['state_name' => 'mist.wifi.channel']
            );

            $translation = StateTranslation::where('state_index_id', $stateIndex->state_index_id)
                ->where('state_value', $channel)
                ->first();

            if (! $translation) {
                $translation = new StateTranslation;
                $translation->state_index_id = $stateIndex->state_index_id;
                $translation->state_value = $channel;
                $translation->state_descr = (string) $channel;
                $translation->state_draw_graph = 1;
                $translation->state_generic_value = 0; // Ok
                $translation->save();
            }

            SensorToStateIndex::updateOrCreate(
                ['sensor_id' => $stateSensor->sensor_id],
                ['state_index_id' => $stateIndex->state_index_id]
            );
        }

        // TX power (dBm)
        if (isset($radio['power']) && is_numeric($radio['power'])) {
            $sensors->push((new LegacyWirelessSensor('power', $device->device_id, [], 'mist', $indexPrefix . '_power', "$label TX Power", (int) $radio['power']))->toModel());
        }

        // Noise floor (dBm)
        if (isset($radio['noise_floor']) && is_numeric($radio['noise_floor'])) {
            $sensors->push((new LegacyWirelessSensor('noise-floor', $device->device_id, [], 'mist', $indexPrefix . '_noise', "$label Noise Floor", (int) $radio['noise_floor']))->toModel());
        }

        // Utilization breakdown (percent)
        $utilMap = [
            'util_all' => 'Utilization',
            'util_tx' => 'TX Utilization',
            'util_rx_in_bss' => 'RX (in BSS) Utilization',
            'util_rx_other_bss' => 'RX (other BSS) Utilization',
            'util_unknown_wifi' => 'Unknown WiFi Utilization',
            'util_non_wifi' => 'Non-WiFi Utilization',
            'util_undecodable_wifi' => 'Undecodable WiFi Utilization',
        ];

        foreach ($utilMap as $key => $desc) {
            if (! array_key_exists($key, $radio) || ! is_numeric($radio[$key])) {
                continue;
            }
            $val = (int) $radio[$key];
            $sensors->push((new LegacyWirelessSensor(
                'utilization',
                $device->device_id,
                [],
                'mist',
                $indexPrefix . '_' . $key,
                "$label $desc",
                $val,
                1,
                1,
                'sum',
                null,
                100,
                0
            ))->toModel());
        }
    }

    protected function updateSensor(WirelessSensor $sensor, OS $os, DataStorageInterface $datastore): void
    {
        // populate sensor_prev and save to db
        $sensor->sensor_prev = $sensor->getOriginal('sensor_current');
        $sensor->save();

        // update rrd and database
        $rrd_name = [
            'wireless-sensor',
            $sensor->sensor_class,
            $sensor->sensor_type,
            $sensor->sensor_index,
        ];
        $rrd_def = RrdDefinition::make()->addDataset('sensor', $sensor->rrd_type);

        $fields = [
            'sensor' => $sensor->sensor_current,
        ];

        $tags = [
            'sensor_class' => $sensor->sensor_class,
            'sensor_type' => $sensor->sensor_type,
            'sensor_descr' => $sensor->sensor_descr,
            'sensor_index' => $sensor->sensor_index,
            'rrd_name' => $rrd_name,
            'rrd_def' => $rrd_def,
        ];
        $datastore->put($os->getDeviceArray(), 'wireless-sensor', $tags, $fields);

        Log::info("  $sensor->sensor_descr: $sensor->sensor_current " . __("wireless.$sensor->sensor_class.unit"));
    }

    public function dataExists(Device $device): bool
    {
        return $device->ports()->exists()
            || $device->wirelessSensors()->exists()
            || $device->processors()->exists()
            || $device->mempools()->exists()
            || $device->sensors()->exists()
            || Link::where('local_device_id', $device->device_id)->where('protocol', 'lldp')->exists();
    }

    public function cleanup(Device $device): int
    {
        $count = 0;
        $count += Link::where('local_device_id', $device->device_id)->where('protocol', 'lldp')->delete();
        $count += $device->ports()->delete();
        $count += $device->wirelessSensors()->delete();
        $count += $device->processors()->delete();
        $count += $device->mempools()->delete();
        $count += $device->sensors()->where('poller_type', 'api')->delete();

        return $count;
    }

    public function dump(Device $device, string $type): ?array
    {
        if ($type === 'poller') {
            return null;
        }

        return [
            'ports' => $device->ports()->get()->map->makeHidden(['device_id', 'port_id', 'deleted']),
            'wireless_sensors' => $device->wirelessSensors()->get()->map->makeHidden(['device_id', 'sensor_id', 'deleted']),
            'processors' => $device->processors()->get()->map->makeHidden(['device_id', 'processor_id']),
            'mempools' => $device->mempools()->get()->map->makeHidden(['device_id', 'mempool_id']),
            'sensors' => $device->sensors()->where('poller_type', 'api')->get()->map->makeHidden(['device_id', 'sensor_id']),
            'links' => Link::where('local_device_id', $device->device_id)->where('protocol', 'lldp')->get()->makeHidden(['id']),
        ];
    }
}
