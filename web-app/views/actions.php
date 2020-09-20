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

$configs = [
    'fsck'    => 'fsck',
];
?>

<h2 class="mt-8">Greyhole Actions</h2>

<ul class="nav nav-tabs" id="myTabsSamba" role="tablist" data-name="paction">
    <?php $first = empty($_GET['paction']); foreach ($configs as $id => $name) : $active = $first || @$_GET['paction'] == 'id_' . $id . '_tab'; if ($active) $selected_tab = $id; ?>
        <li class="nav-item">
            <a class="nav-link <?php echo $active ? 'active' : '' ?>"
               id="id_<?php echo $id ?>_tab"
               data-toggle="tab"
               href="#id_<?php echo $id ?>"
               role="tab"
               aria-controls="id_<?php echo $id ?>"
               aria-selected="<?php echo $active ? 'true' : 'false' ?>"><?php phe($name) ?></a>
        </li>
    <?php $first = FALSE; endforeach; ?>
</ul>
<div class="tab-content" id="myTabContentActions">
    <div class="tab-pane fade <?php if ($selected_tab == 'fsck') echo 'show active' ?>" id="id_fsck" role="tabpanel" aria-labelledby="id-fsck-tab">
        <div class="input_group mt-4">
            <?php echo get_config_html(['name' => 'email-report', 'display_name' => 'Email Report', 'type' => 'bool', 'help' => "Send an email when fsck completes, to report on what was checked, and any error that was found.", 'onchange' => FALSE], TRUE) ?>
            <?php echo get_config_html(['name' => 'disk-usage-report', 'display_name' => 'Calculate Disk Usage', 'type' => 'bool', 'help' => "Calculate the disk usage of scanned folders & files.", 'onchange' => FALSE], TRUE) ?>
            <?php echo get_config_html(['name' => 'walk-metadata-store', 'display_name' => 'Walk Metadata Store', 'type' => 'bool', 'help' => "You can speed up fsck by turning off this option, in order to skip the scan of the metadata store directories. Scanning the metadata stores is only required to re-create symbolic links that might be missing from your shared directories.", 'onchange' => FALSE], TRUE) ?>
            <?php echo get_config_html(['name' => 'find-orphaned-files', 'display_name' => 'Find Orphaned Files', 'type' => 'bool', 'help' => "Scan for files with no metadata in the storage pool drives. This will allow you to include existing files on a drive in your storage pool without having to copy them manually.", 'onchange' => FALSE], FALSE) ?>
            <?php echo get_config_html(['name' => 'delete-orphaned-metadata', 'display_name' => 'Delete Orphaned Metadata', 'type' => 'bool', 'help' => "When fsck find metadata files with no file copies, delete those metadata files. If the file copies re-appear later, you'll need to run fsck with --find-orphaned-files to have them reappear in your shares.", 'onchange' => FALSE], FALSE) ?>
            <?php echo get_config_html(['name' => 'checksums', 'display_name' => 'Checksum all files', 'type' => 'bool', 'help' => "Read ALL files in your storage pool, and check that file copies are identical. This will identify any problem you might have with your file-systems. NOTE: this can take a LONG time to complete, since it will read everything from all your drives!", 'onchange' => FALSE], FALSE) ?>
            <?php
            $possible_values = [];
            $possible_values[''] = 'All shares';
            foreach (SharesConfig::getShares() as $share_name => $share_options) {
                $possible_values[$share_options[CONFIG_LANDING_ZONE]] = "Share: $share_name";
            }
            foreach (Config::storagePoolDrives() as $sp_drive) {
                $possible_values[$sp_drive] = "Drive: $sp_drive";
            }
            ?>
            <?php echo get_config_html(['name' => 'dir', 'display_name' => 'Folder', 'type' => 'select', 'possible_values' => $possible_values, 'help' => "Choose a share or storage pool drive to scan.", 'onchange' => FALSE]) ?>
            <button type="button" class="btn btn-primary mt-2" data-toggle="modal" data-target="#modal-confirm-fsck" onclick="confirmFsckCommand(this)">
                Start fsck...
            </button>
        </div>
    </div>
</div>
<div id="modal-confirm-fsck" class="modal" tabindex="-1" role="dialog">
    <div class="modal-dialog" role="document">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">Confirm fsck parameters</h5>
                <button type="button" class="close" data-dismiss="modal" aria-label="Close">
                    <span aria-hidden="true">&times;</span>
                </button>
            </div>
            <div class="modal-body">
                <div class="command">
                    Command:<br/>
                    <code></code>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-primary" onclick="startFsck(this)">Start fsck</button>
                <button type="button" class="btn btn-secondary" data-dismiss="modal">Cancel</button>
            </div>
        </div>
    </div>
</div>
