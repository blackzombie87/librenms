<?php

require 'includes/html/graphs/accesspoints/common.inc.php';

$rrd_filename = $ap_rrd_filename;
if ($is_mist_ap) {
    $rrd_list[0]['filename'] = $rrd_filename;
    $rrd_list[0]['descr'] = '2.4 GHz';
    $rrd_list[0]['ds'] = 'band24_util';
    $rrd_list[1]['filename'] = $rrd_filename;
    $rrd_list[1]['descr'] = '5 GHz';
    $rrd_list[1]['ds'] = 'band5_util';
} else {
    $rrd_list[0]['filename'] = $rrd_filename;
    $rrd_list[0]['descr'] = 'radioutil';
    $rrd_list[0]['ds'] = 'radioutil';
}

$unit_text = 'Percent';

$units = '';
$total_units = '';
$colours = 'mixed';

$scale_min = '0';

$nototal = 1;

if ($rrd_list) {
    include 'includes/html/graphs/generic_multi_line.inc.php';
}
