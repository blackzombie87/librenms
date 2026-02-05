<?php

require 'includes/html/graphs/accesspoints/common.inc.php';

if ($is_mist_ap) {
    graph_error('Interference not recorded for Mist APs', 'No Data');
    exit;
}

$rrd_filename = $ap_rrd_filename;
$rrd_list[0]['filename'] = $rrd_filename;
$rrd_list[0]['descr'] = 'Interference';
$rrd_list[0]['ds'] = 'interference';

$unit_text = 'Int';

$units = '';
$total_units = '';
$colours = 'mixed';

$scale_min = '0';

$nototal = 1;

if ($rrd_list) {
    include 'includes/html/graphs/generic_multi_line.inc.php';
}
