<?php
/*
Copyright 2013-2020 Guillaume Boudreau

This file is part of Greyhole.

Greyhole is free software: you can redistribute it and/or modify
it under the terms of the GNU General Public License as published by
the Free Software Foundation, either version 3 of the License, or
(at your option) any later version.

Greyhole is distributed in the hope that it will be useful,
but WITHOUT ANY WARRANTY; without even the implied warranty of
MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
GNU General Public License for more details.

You should have received a copy of the GNU General Public License
along with Greyhole.  If not, see <http://www.gnu.org/licenses/>.
*/

include(__DIR__ . '/../init.inc.php');

if (isset($_REQUEST['level'])) {
    $level_min = (int) $_REQUEST['level'];
} else {
    $level_min = 1;
}
$level_max = $level_min+1;

if (isset($_REQUEST['path'])) {
    $path = $_REQUEST['path'];
} else {
    $path = '/';
}

if ($level_min > 1) {
    $p = explode('/', trim($path, '/'));
    $share = array_shift($p);
    $full_path = implode('/', $p);

    $query = "SELECT size FROM du_stats WHERE depth = :depth AND share = :share AND full_path = :full_path";
    $params = array(
        'depth' => $level_min - 1,
        'share' => $share,
        'full_path' => $full_path
    );
    $total_bytes = (float) DB::getFirstValue($query, $params);

    $query = "SELECT size, depth, CONCAT('/', share, '/', full_path) AS file_path FROM du_stats WHERE depth = :depth AND share = :share AND full_path LIKE :full_path ORDER BY size DESC";
    $params = [
		'depth' => $level_min,
		'share' => $share,
		'full_path' => empty($full_path) ? '%' : "$full_path/%"
    ];
} else {
	$query = "SELECT size, depth, CONCAT('/', share) AS file_path FROM du_stats WHERE depth = 1 ORDER BY size DESC";
    $params = [];
}
$rows = DB::getAll($query, $params);

$total_bytes_subfolders = 0;
foreach ($rows as $row) {
    $row->depth = (int) $row->depth;
    $row->size = (float) $row->size;
    if ($row->depth == $level_min) {
        $total_bytes_subfolders += $row->size;
    }
}
if ($level_min == 1) {
    $total_bytes = $total_bytes_subfolders;
}
$total_bytes_files = $total_bytes - $total_bytes_subfolders;
if ($total_bytes_files > 0) {
    $rows[] = (object) ['size' => $total_bytes_files, 'depth' => $level_min, 'file_path' => 'Files'];
}
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, shrink-to-fit=no">
    <?php if ($is_dark_mode) : ?>
        <link rel="stylesheet" href="https://stackpath.bootstrapcdn.com/bootswatch/4.5.2/darkly/bootstrap.min.css" integrity="sha384-nNK9n28pDUDDgIiIqZ/MiyO3F4/9vsMtReZK39klb/MtkZI3/LtjSjlmyVPS3KdN" crossorigin="anonymous">
    <?php else : ?>
        <link rel="stylesheet" href="https://stackpath.bootstrapcdn.com/bootstrap/4.5.2/css/bootstrap.min.css" integrity="sha384-JcKb8q3iqJ61gNV9KGb8thSsNjpSL0n8PARn9HuZOnIxN0hoP+VmmDGMN5t9UJ0Z" crossorigin="anonymous">
    <?php endif; ?>
    <script src="https://cdn.jsdelivr.net/npm/chart.js@2.9.3/dist/Chart.min.js" integrity="sha256-R4pqcOYV8lt7snxMQO/HSbVCFRPMdrhAFMH+vr9giYI=" crossorigin="anonymous"></script>
    <script src="https://cdn.jsdelivr.net/npm/chartjs-chart-treemap@0.2.3/dist/chartjs-chart-treemap.min.js" integrity="sha256-K09i+g2CdFo3xmqlfWpy7c6f0yeyu5Qgj+9eL0XvOdU=" crossorigin="anonymous"></script>
    <script src="../scripts.js"></script>
    <link rel="stylesheet" href="../styles.css">
    <link rel="shortcut icon" type="image/png" href="../favicon.png" sizes="64x64">
    <title>Shares Disk Usage - <?php phe($level_min > 1 ? "$path - " : "") ?>Greyhole Admin</title>
</head>
<body class="<?php if ($is_dark_mode) echo "dark" ?>">

<div class="container-fluid">
    <nav class="navbar navbar-expand-md navbar-dark bg-dark">
        <div class="navbar-brand">
            <a href="../">&lt; Back to Greyhole Admin</a>
        </div>
    </nav>

    <h2 class="mt-8 mb-4">
        Shares Disk Usage
        <?php
        if ($level_min > 1) {
            $paths = explode('/', $path);
            $this_folder = array_pop($paths);
            $level = 1;
            $pp = '';
            foreach ($paths as $p) {
                $pp = "$pp/$p";
                if ($pp == '/') {
                    $pp = '';
                    $p = 'root';
                    echo " -";
                } else {
                    echo "/";
                }
                echo " <a href='./?level=$level&path=" . urlencode($pp) . "'>" . he($p) . "</a> ";
                $level++;
            }
            phe("/ $this_folder");
        }
        ?>
        - <?php echo bytes_to_human($total_bytes, TRUE, TRUE) ?>
    </h2>
    <div class="treemap_container">
        <canvas id="treemap_shares_usage" width="200" height="200"></canvas>
    </div>
    <script>
        let dark_mode_enabled = <?php echo json_encode($is_dark_mode) ?>;
        defer(function() {
            let ctx = document.getElementById('treemap_shares_usage').getContext('2d');
            drawTreeMapDiskUsage(ctx, <?php echo json_encode($rows) ?>);
        });
    </script>

    <div class="col">
        <?php
        $max = 0; $min = NULL;
        foreach ($rows as $row) {
            if ($row->size > $max) {
                $max = $row->size;
            }
            if ($min === NULL || $row->size < $min) {
                $min = $row->size;
            }
        }
        ?>
        <table id="table-sp-drives" class="mt-4" data-min-value="<?php echo $min ?>" data-max-value="<?php echo $max ?>">
            <thead>
            <tr>
                <th>Path</th>
                <th>Size</th>
                <th>Usage</th>
            </tr>
            </thead>
            <tbody>
            <?php foreach ($rows as $row) : ?>
                <tr>
                    <td>
                        <?php if ($row->file_path == 'Files') : ?>
                            Files
                        <?php else : ?>
                            <a href="./?level=<?php echo $row->depth+1 ?>&path=<?php echo urlencode($row->file_path) ?>"><?php phe(basename($row->file_path)) ?></a>
                        <?php endif; ?>
                    </td>
                    <td>
                        <?php echo bytes_to_human($row->size, TRUE, TRUE) ?>
                    </td>
                    <td class="sp-bar-td">
                        <?php if ($row->file_path == 'Files') : ?>
                            <div class="sp-bar treemap used nolink" data-value="<?php echo $row->size ?>" data-width="<?php echo ($row->size/$max) ?>"></div>
                        <?php else : ?>
                            <a onclick="location.href='./?level=<?php echo $row->depth+1 ?>&path=<?php echo urlencode($row->file_path) ?>'" class="sp-bar treemap used" data-value="<?php echo $row->size ?>" data-width="<?php echo ($row->size/$max) ?>"></a>
                        <?php endif; ?>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>

<div id="footer-padding"></div>

<script src="https://cdn.jsdelivr.net/npm/jquery@3.5.1/dist/jquery.min.js" integrity="sha256-9/aliU8dGd2tb6OSsuzixeV4y/faTqgFtohetphbbj0=" crossorigin="anonymous"></script>
<script src="https://cdn.jsdelivr.net/npm/popper.js@1.16.1/dist/umd/popper.min.js" integrity="sha384-9/reFTGAW83EW2RDu2S0VKaIzap3H66lZH81PoYlFhbGU+6BZp6G7niu735Sk7lN" crossorigin="anonymous"></script>
<script src="https://stackpath.bootstrapcdn.com/bootstrap/4.5.2/js/bootstrap.min.js" integrity="sha384-B4gt1jrGC7Jh4AgTPSdUtOBvfO8shuf57BaghqFfPlYxofvL8/KUEfYiJOMMV+rV" crossorigin="anonymous"></script>
</body>
</html>
