<?php

require 'includes/html/graphs/accesspoints/common.inc.php';

$rrd_filename = $ap_rrd_filename;
$rrd_list[0]['filename'] = $rrd_filename;
$rrd_list[0]['descr'] = $is_mist_ap ? 'Clients' : 'Num Clients';
$rrd_list[0]['ds'] = $is_mist_ap ? 'clients' : 'numasoclients';

$unit_text = 'Clients';

$units = '';
$total_units = '';
$colours = 'mixed';

$scale_min = '0';

$nototal = 1;

if ($rrd_list) {
    include 'includes/html/graphs/generic_multi_line.inc.php';
}
