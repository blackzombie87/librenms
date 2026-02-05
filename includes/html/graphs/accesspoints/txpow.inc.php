<?php

require 'includes/html/graphs/accesspoints/common.inc.php';

if ($is_mist_ap) {
    graph_error('Transmit power not recorded for Mist APs', 'No Data');
    exit;
}

$rrd_filename = $ap_rrd_filename;
$rrd_list[0]['filename'] = $rrd_filename;
$rrd_list[0]['descr'] = 'txpow';
$rrd_list[0]['ds'] = 'txpow';

$unit_text = 'dBm';

$units = '';
$total_units = '';
$colours = 'mixed';

$scale_min = '0';

$nototal = 1;

if ($rrd_list) {
    include 'includes/html/graphs/generic_multi_line.inc.php';
}
