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

$cols_width = defined('IS_INITIAL_SETUP') ? 'col-12' : 'col-12 col-lg-6';
?>

<?php if (defined('IS_INITIAL_SETUP')) : ?>
    <h2 class="mt-8">Create Storage Pool</h2>
    <div class="mt-3 mb-3">
        Add as many drives as you want to your storage pool.<br/>
        The <code>Path</code> you enter for each drive should be an empty folder (unless you are <a href="https://github.com/gboudreau/Greyhole/wiki/ReuseDataDrives" target="_blank">migrating data</a>).<br/>
        Suggestion: <code>/path/to/mounted/drive/gh</code>
    </div>
<?php else : ?>
    <h2 class="mt-8">Storage Pool</h2>
<?php endif; ?>

<?php $stats = StatsCliRunner::get_stats() ?>
<div class="row">
    <div class="col <?php echo $cols_width ?>">
        <?php if (count($stats) > 1) : ?>
            <table id="table-sp-drives">
                <thead>
                <tr>
                    <th>Path</th>
                    <th>Min. free space</th>
                    <th>Size</th>
                    <th>Usage</th>
                </tr>
                </thead>
                <tbody>
                <?php
                $max = 0;
                foreach ($stats as $sp_drive => $stat) {
                    if ($sp_drive == 'Total') continue;
                    if ($stat->total_space > $max) {
                        $max = $stat->total_space;
                    }
                }
                ?>
                <?php foreach ($stats as $sp_drive => $stat) : ?>
                    <?php if ($sp_drive == 'Total') continue; ?>
                    <tr>
                        <td>
                            <?php phe($sp_drive) ?>
                        </td>
                        <td>
                            <?php echo get_config_html(['name' => CONFIG_MIN_FREE_SPACE_POOL_DRIVE . "[$sp_drive]", 'type' => 'kbytes'], Config::get(CONFIG_MIN_FREE_SPACE_POOL_DRIVE, $sp_drive)) ?>
                        </td>
                        <td>
                            <?php if (empty($stat->total_space)) : ?>
                                Offline
                            <?php else : ?>
                                <?php echo bytes_to_human($stat->total_space*1024, TRUE, TRUE) ?>
                            <?php endif; ?>
                        </td>
                        <td class="sp-bar-td">
                            <?php if (!empty($stat->total_space)) : ?>
                                <div class="sp-bar used" data-width="<?php echo (($stat->used_space - $stat->trash_size)/$max) ?>" data-toggle="tooltip" data-placement="bottom" title="<?php phe("Used: " . bytes_to_human(($stat->used_space - $stat->trash_size)*1024, FALSE, TRUE)) ?>">
                                </div><div class="sp-bar trash" data-width="<?php echo ($stat->trash_size/$max) ?>" data-toggle="tooltip" data-placement="bottom" title="<?php phe("Trash: " . bytes_to_human($stat->trash_size*1024, FALSE, TRUE)) ?>">
                                </div><div class="sp-bar free" data-width="<?php echo ($stat->free_space/$max) ?>" data-toggle="tooltip" data-placement="bottom" title="<?php phe("Free: " . bytes_to_human($stat->free_space*1024, FALSE, TRUE)) ?>"></div>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>
        <button type="button" class="btn btn-primary mt-2" data-toggle="modal" data-target="#modal-storage-pool-drive">
            Add Drive to Storage Pool
        </button>
    </div>
    <?php if (!defined('IS_INITIAL_SETUP')) : ?>
        <div class="col <?php echo $cols_width ?>">
            <div class="chart-container">
                <canvas id="chart_storage_pool" width="200" height="200"></canvas>
            </div>
            <script>
                defer(function(){
                    let ctx = document.getElementById('chart_storage_pool').getContext('2d');
                    drawPieChartStorage(ctx, <?php echo json_encode($stats) ?>);
                });
            </script>
        </div>
    <?php endif; ?>
</div>
<div id="modal-storage-pool-drive" class="modal" tabindex="-1" role="dialog">
    <div class="modal-dialog" role="document">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">Add Drive to Storage Pool</h5>
                <button type="button" class="close" data-dismiss="modal" aria-label="Close">
                    <span aria-hidden="true">&times;</span>
                </button>
            </div>
            <div class="modal-body">
                <div class="mb-1">Path</div>
                <?php echo get_config_html(['name' => CONFIG_STORAGE_POOL_DRIVE, 'type' => 'string', 'help' => "Specify the absolute path to an empty folder on a new drive.", 'placeholder' => "ex. /mnt/hdd3/gh", 'onchange' => FALSE], '') ?>
                <div class="mb-1">Min. free space</div>
                <?php echo get_config_html(['name' => CONFIG_MIN_FREE_SPACE_POOL_DRIVE . "[__new__]", 'type' => 'kbytes', 'help' => "Specify how much free space you want to reserve on each drive. This is a soft limit that will be ignored if the all the necessary hard drives are below their minimum.", 'onchange' => FALSE], 10*1024*1024) ?>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-primary" onclick="addStoragePoolDrive(this)">Add</button>
                <button type="button" class="btn btn-secondary" data-dismiss="modal">Cancel</button>
            </div>
        </div>
    </div>
</div>
