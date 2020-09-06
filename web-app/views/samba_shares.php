<?php
/*
Copyright 2020 Guillaume Boudreau

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
?>

<h2 class="mt-8">Samba Shares</h2>

<?php
$max = count(Config::storagePoolDrives());
foreach (SharesConfig::getShares() as $share_name => $share_options) {
    if ($share_options[CONFIG_NUM_COPIES] > $max) {
        $max = $share_options[CONFIG_NUM_COPIES];
    }
    if (is_numeric($share_options[CONFIG_NUM_COPIES . '_raw']) && $share_options[CONFIG_NUM_COPIES . '_raw'] > $max) {
        $max = $share_options[CONFIG_NUM_COPIES . '_raw'];
    }
}

$possible_values_num_copies = ['0' => 'Disabled'];
for ($i=1; $i<=$max; $i++) {
    $possible_values_num_copies[(string) $i] = $i;
}
$possible_values_num_copies['max'] = 'Max';

unset($output);
exec("/usr/bin/testparm -sl 2>/dev/null | grep '\[' | grep -vi '\[global]'", $output);
$all_samba_shares = [];
foreach ($output as $line) {
    if (preg_match('/\s*\[(.+)]\s*$/', $line, $re)) {
        $share_name = $re[1];
        if (array_contains(ConfigHelper::$trash_share_names, $share_name)) {
            $share_options = SharesConfig::getConfigForShare(CONFIG_TRASH_SHARE);
            $share_options['is_trash'] = TRUE;
        } else {
            $share_options = SharesConfig::getConfigForShare($share_name);
        }
        if (empty($share_options)) {
            $share_options['landing_zone'] = exec("/usr/bin/testparm -sl --parameter-name='path' --section-name=" . escapeshellarg($share_name) . " 2>/dev/null");
            $share_options[CONFIG_NUM_COPIES . '_raw'] = '0';
        }
        $share_options['vfs_objects'] = exec("/usr/bin/testparm -sl --parameter-name='vfs objects' --section-name=" . escapeshellarg($share_name) . " 2>/dev/null");
        if (empty($share_options['landing_zone'])) {
            continue;
        }
        $all_samba_shares[$share_name] = $share_options;
    }
}
natksort($all_samba_shares);
?>
<div class="row">
    <div class="col col-sm-12 col-lg-6">
        <table id="table-shares">
            <thead>
            <tr>
                <th>Share</th>
                <th>Landing zone</th>
                <th>Greyhole-enabled?</th>
                <th>Number of file copies</th>
            </tr>
            </thead>
            <tbody>
            <?php foreach ($all_samba_shares as $share_name => $share_options) : ?>
                <tr>
                    <td><?php phe($share_name) ?></td>
                    <td><code><?php phe($share_options['landing_zone']) ?></code></td>
                    <td class="centered">
                        <?php
                        if (@$share_options['is_trash']) {
                            echo '<a href="https://github.com/gboudreau/Greyhole/wiki/AboutTrash" target="_blank">Greyhole Trash</a>';
                        } else {
                            echo get_config_html(['name' => "gh_enabled[$share_name]", 'type' => 'bool', 'onchange' => 'toggleSambaShareGreyholeEnabled(this)', 'data' => ['sharename' => $share_name]], @$share_options[CONFIG_NUM_COPIES . '_raw'] !== '0', FALSE);
                        }
                        ?>
                    </td>
                    <td class="centered">
                        <?php
                        if (@$share_options['is_trash']) {
                            echo 'N/A';
                        } else {
                            echo get_config_html(['name' => CONFIG_NUM_COPIES . "[$share_name]", 'type' => 'select', 'possible_values' => $possible_values_num_copies], $share_options[CONFIG_NUM_COPIES . '_raw'], FALSE);
                        }
                        ?>
                        <input type="hidden" name="vfs_objects[<?php phe($share_name) ?>]" value="<?php phe($share_options['vfs_objects']) ?>" />
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        <button type="button" class="btn btn-primary mt-2" data-toggle="modal" data-target="#modal-add-samba-share">
            Add Samba Share
        </button>
    </div>
    <div class="col col-sm-12 col-lg-6">
        <?php if (DB::isConnected()) : ?>
            <?php
            $q = "SELECT size, depth, share AS file_path FROM du_stats WHERE depth = 1 ORDER BY size DESC";
            $rows = DB::getAll($q);
            ?>
            <div class="chart-container">
                <canvas id="chart_shares_usage" width="200" height="200"></canvas>
            </div>
            <script>
                defer(function(){
                    let ctx = document.getElementById('chart_shares_usage').getContext('2d');
                    drawPieChartDiskUsage(ctx, <?php echo json_encode($rows) ?>);
                });
            </script>
        <?php else : ?>
            (Warning: Can't connect to database to load disk usage statistics.)
        <?php endif; ?>
    </div>
</div>
<div id="modal-add-samba-share" class="modal" tabindex="-1" role="dialog">
    <div class="modal-dialog" role="document">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">Add Samba Share</h5>
                <button type="button" class="close" data-dismiss="modal" aria-label="Close">
                    <span aria-hidden="true">&times;</span>
                </button>
            </div>
            <div class="modal-body">
                <?php list($default_path, $options) = get_new_share_defaults($all_samba_shares); ?>
                <div class="mb-1">Share Name</div>
                <?php echo get_config_html(['name' => 'samba_share_name', 'type' => 'string', 'onchange' => 'updateSambaSharePath(this)', 'placeholder' => "eg. Videos", 'width' => 460], '', FALSE) ?>
                <div class="mb-1">Path (Landing zone)</div>
                <?php echo get_config_html(['name' => 'samba_share_path', 'type' => 'string', 'onchange' => FALSE, 'width' => 460, 'placeholder' => '/path/to/your/share'], $default_path, FALSE) ?>
                <div class="mb-1">Additional Options</div>
                <?php echo get_config_html(['name' => 'samba_share_options', 'type' => 'multi-string', 'onchange' => FALSE, 'width' => 460], $options, FALSE) ?>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-primary" onclick="addSambaShare(this)">Create Share</button>
                <button type="button" class="btn btn-secondary" data-dismiss="modal">Cancel</button>
            </div>
        </div>
    </div>
</div>
