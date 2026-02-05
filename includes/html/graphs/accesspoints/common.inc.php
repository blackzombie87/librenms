<?php

/**
 * Common RRD path for access point graphs.
 * Mist APs use mist-ap/name-mac; other (e.g. Aruba) use arubaap/name+radio_number.
 */
$is_mist_ap = isset($device['os']) && $device['os'] === 'mist';

if ($is_mist_ap) {
    $ap_rrd_name = ['mist-ap', $ap['name'] . '-' . ($ap['mac_addr'] ?? '')];
} else {
    $ap_rrd_name = ['arubaap', $ap['name'] . ($ap['radio_number'] ?? 0)];
}

$ap_rrd_filename = Rrd::name($device['hostname'], $ap_rrd_name);
