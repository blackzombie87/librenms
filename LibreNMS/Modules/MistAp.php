<?php

namespace LibreNMS\Modules;

use App\ApiClients\MistApi;
use App\Facades\DeviceCache;
use App\Models\Device;
use App\Models\Port;
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

        if (! $status->isEnabledAndDeviceUp($device, false)) {
            return false;
        }

        if ($device->os !== 'mist-ap') {
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

            // Update device basic info
            $device->hardware = $apData['model'] ?? $device->hardware;
            $device->version = $apData['version'] ?? $device->version;
            $device->serial = $apData['serial'] ?? $device->serial;
            $device->uptime = $apData['uptime'] ?? $device->uptime;
            $device->sysDescr = ($apData['model'] ?? 'Mist AP') . ' - ' . ($apData['version'] ?? 'Unknown');
            $device->save();

            // Update ports (ethernet interfaces)
            $this->updatePorts($device, $apData, $apStats, $datastore);

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

            // Store RRD data
            $rrdDef = RrdDefinition::make()
                ->addDataset('INOCTETS', 'COUNTER', 0)
                ->addDataset('OUTOCTETS', 'COUNTER', 0);

            $datastore->put($device->toArray(), 'port', [
                'ifName' => $ifName,
                'rrd_name' => ['port', $ifName],
                'rrd_def' => $rrdDef,
            ], [
                'INOCTETS' => $port->ifInOctets,
                'OUTOCTETS' => $port->ifOutOctets,
            ]);

            $ifIndex++;
        }
    }

    private function updateWirelessSensors(OS $os, Device $device, array $apData, array $apStats, DataStorageInterface $datastore): void
    {
        $existingSensors = $device->wirelessSensors()->get()->keyBy(fn ($s) => $s->sensor_class . '_' . $s->sensor_index);
        $sensors = collect();

        // Client count
        $numClients = (int) ($apStats['num_clients'] ?? 0);
        $sensors->push((new LegacyWirelessSensor('clients', $device->device_id, [], 'mist', 'total', 'Total Clients', $numClients))->toModel());

        // Radio stats (present for connected APs)
        $band24 = $apStats['radio_stat']['band_24'] ?? [];
        $band5 = $apStats['radio_stat']['band_5'] ?? [];

        $this->addRadioSensors($sensors, $device, '2.4 GHz', 'band24', is_array($band24) ? $band24 : []);
        $this->addRadioSensors($sensors, $device, '5 GHz', 'band5', is_array($band5) ? $band5 : []);

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
            $sensors->push((new LegacyWirelessSensor('channel', $device->device_id, [], 'mist', $indexPrefix . '_channel', "$label Channel", (int) $radio['channel']))->toModel());
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
        return $device->ports()->exists() || $device->wirelessSensors()->exists();
    }

    public function cleanup(Device $device): int
    {
        $portsDeleted = $device->ports()->delete();
        $sensorsDeleted = $device->wirelessSensors()->delete();

        return $portsDeleted + $sensorsDeleted;
    }

    public function dump(Device $device, string $type): ?array
    {
        if ($type === 'poller') {
            return null;
        }

        return [
            'ports' => $device->ports()->get()->map->makeHidden(['device_id', 'port_id', 'deleted']),
            'wireless_sensors' => $device->wirelessSensors()->get()->map->makeHidden(['device_id', 'sensor_id', 'deleted']),
        ];
    }
}
