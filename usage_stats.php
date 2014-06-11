<?php

require_once('usage_stats_config.php');

$data = file_get_contents('php://input');
$uid = substr($data, 0, 23);
$stats = substr($data, 23);

$stats_o = json_decode($stats);
if (!$stats_o) {
	//error_log("greyhole.net/usage_stats.php received the following invalid JSON in POST: $data");
	exit(1);
}
$total_space = $stats_o->Total->total_space;
$used_space = $stats_o->Total->used_space;

echo "Hello $uid. Thank you for calling home to report a working Greyhole install of ".number_format($total_space/1024/1024, 0)."GB!\n";

try {
    DB::connect();

    $q = "INSERT INTO gh_usage (caller, stats, total_space, used_space) VALUES (:uid, :stats, :total_space, :used_space) ON DUPLICATE KEY UPDATE stats = VALUES(stats), total_space = VALUES(total_space), used_space = VALUES(used_space), event_date = NOW()";
    DB::insert($q, array(
        'uid' => $uid,
        'stats' => $stats,
        'total_space' => $total_space/1024/1024,
        'used_space' => $used_space/1024/1024,
    ));
} catch (Exception $ex) {
    error_log($ex);
}

?>
