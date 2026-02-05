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
        $ethernetInterfaces = $apData['ethernet_interfaces'] ?? [];
        $portStats = $apStats['ethernet_port_stats'] ?? [];

        foreach ($ethernetInterfaces as $eth) {
            $ifName = $eth['name'] ?? 'eth' . ($eth['index'] ?? 0);
            $ifIndex = $eth['index'] ?? 0;

            // Find or create port
            $port = Port::firstOrNew([
                'device_id' => $device->device_id,
                'ifIndex' => $ifIndex,
            ], [
                'ifName' => $ifName,
                'ifDescr' => $eth['description'] ?? $ifName,
                'ifType' => 6, // ethernetCsmacd
                'ifOperStatus' => ($eth['up'] ?? false) ? 'up' : 'down',
                'ifAdminStatus' => ($eth['up'] ?? false) ? 'up' : 'down',
                'ifSpeed' => $eth['speed'] ?? 0,
                'ifDuplex' => ($eth['full_duplex'] ?? false) ? 'fullDuplex' : 'halfDuplex',
            ]);

            $port->ifName = $ifName;
            $port->ifDescr = $eth['description'] ?? $ifName;
            $port->ifOperStatus = ($eth['up'] ?? false) ? 'up' : 'down';
            $port->ifAdminStatus = ($eth['up'] ?? false) ? 'up' : 'down';
            $port->ifSpeed = $eth['speed'] ?? 0;
            $port->ifDuplex = ($eth['full_duplex'] ?? false) ? 'fullDuplex' : 'halfDuplex';
            $port->save();

            // Update port statistics if available
            $stats = $portStats[$ifIndex] ?? null;
            if ($stats) {
                $port->ifInOctets = $stats['rx_bytes'] ?? 0;
                $port->ifOutOctets = $stats['tx_bytes'] ?? 0;
                $port->ifInUcastPkts = $stats['rx_packets'] ?? 0;
                $port->ifOutUcastPkts = $stats['tx_packets'] ?? 0;
                $port->ifInErrors = $stats['rx_errors'] ?? 0;
                $port->ifOutErrors = $stats['tx_errors'] ?? 0;
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
            }
        }
    }

    private function updateWirelessSensors(OS $os, Device $device, array $apData, array $apStats, DataStorageInterface $datastore): void
    {
        $existingSensors = $device->wirelessSensors()->get()->keyBy(fn ($s) => $s->sensor_class . '_' . $s->sensor_index);
        $sensors = collect();

        // Client count
        $numClients = (int) ($apStats['num_clients'] ?? 0);
        $sensors->push((new LegacyWirelessSensor('clients', $device->device_id, [], 'mist', 'total', 'Total Clients', $numClients))->toModel());

        // Radio utilization
        $band24 = $apStats['radio_stat']['band_24'] ?? [];
        $band5 = $apStats['radio_stat']['band_5'] ?? [];

        $util24 = (int) ($band24['util_all'] ?? 0);
        $util5 = (int) ($band5['util_all'] ?? 0);

        if ($util24 > 0 || $util5 > 0) {
            $sensors->push((new LegacyWirelessSensor('utilization', $device->device_id, [], 'mist', 'band24', '2.4 GHz Utilization', $util24, 1, 1, null, null, null, null, null, null, null, null, 0, 100))->toModel());
            $sensors->push((new LegacyWirelessSensor('utilization', $device->device_id, [], 'mist', 'band5', '5 GHz Utilization', $util5, 1, 1, null, null, null, null, null, null, null, null, 0, 100))->toModel());
        }

        // Sync sensors and update RRD data
        $synced = $this->syncModels($device, 'wirelessSensors', $sensors, $existingSensors);

        // Update RRD for all sensors
        foreach ($device->wirelessSensors()->get() as $sensor) {
            $this->updateSensor($sensor, $os, $datastore);
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
