<?php

use LibreNMS\OS;
use LibreNMS\OS\Generic;

// Run Mist global discovery once per discovery cycle
static $mist_discovery_run = false;
if (! $mist_discovery_run && file_exists(__DIR__ . '/mist.inc.php')) {
    include __DIR__ . '/mist.inc.php';
    $mist_discovery_run = true;
}

// start assuming no os
(new \LibreNMS\Modules\Core())->discover(Generic::make($device));

// then create with actual OS
$os = OS::make($device);
