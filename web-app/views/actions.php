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

$fsck_tab    = new Tab('action_fsck', 'fsck');
$balance_tab = new Tab('action_balance', 'Balance');
$trash_tab   = new Tab('action_trash', 'Greyhole Trash');
$daemon_tab  = new Tab('action_daemon', 'Daemon');
$remove_tab  = new Tab('action_removedrive', 'Remove Drive');
$tabs = [$fsck_tab, $balance_tab, $trash_tab, $daemon_tab, $remove_tab];
?>

<?php $fsck_tab->startContent() ?>
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
    <button type="button" class="btn btn-primary mt-2" data-toggle="modal" data-target="#modal-confirm-fsck" onclick="confirmFsckCommand()">
        Start fsck...
    </button>
</div>
<?php $fsck_tab->endContent() ?>

<?php $balance_tab->startContent() ?>
<div class="input_group mt-4">
    <div>
        Try to balance <?php ?> on all your storage pool drives (based on your <code>Drive Selection Algorithm</code> config).<br/>
        You can follow the advancement of this operation in the Status page.
    </div>
    <button type="button" class="btn btn-primary mt-2" onclick="startBalance(this)">
        Start Balance
    </button>
</div>
<?php $balance_tab->endContent() ?>

<?php $trash_tab->startContent() ?>
<div class="mt-4">
    Trash content:
    <table id="trash-content">
        <?php global $sp_stats; foreach ($sp_stats as $sp_drive => $stat) : if ($sp_drive == 'Total') continue; ?>
            <tr>
                <td><code><?php phe($sp_drive) ?></code></td>
                <td class="colorize" data-value="<?php phe($stat->trash_size) ?>"><?php echo bytes_to_human($stat->trash_size*1024, TRUE, TRUE) ?></td>
            </tr>
        <?php endforeach; ?>
    </table>
</div>
<button type="button" class="btn btn-primary mt-2" onclick="emptyTrash(this)">
    Empty Trash
</button>
<?php $trash_tab->endContent() ?>

<?php $daemon_tab->startContent() ?>
<div class="mt-4">
    Use this button to temporarily pause and resume the daemon.<br/>
    <?php if (!PauseCliRunner::isPaused()) : ?>
        <button type="button" class="btn btn-primary mt-4" onclick="pauseDaemon(this)">
            Pause Daemon
        </button>
    <?php else : ?>
        <button type="button" class="btn btn-primary mt-4" onclick="resumeDaemon(this)">
            Resume Daemon
        </button>
    <?php endif; ?>
</div>
<?php $daemon_tab->endContent() ?>

<?php $remove_tab->startContent() ?>
<div class="mt-4">
    Tell Greyhole that you want to remove a drive. Greyhole
    will then make sure you don't lose any files, and that
    the correct number of file copies are created to replace
    the missing drive.
    <div class="mt-4">
        <?php
        $possible_values = array_combine(Config::storagePoolDrives(), Config::storagePoolDrives());
        echo get_config_html(['name' => 'remove_drive', 'display_name' => 'Drive to remove', 'type' => 'select', 'possible_values' => $possible_values, 'onchange' => FALSE], NULL, FALSE);
        ?>
        <button type="button" class="btn btn-danger mt-2" data-toggle="modal" data-target="#modal-confirm-remove-drive" onclick="confirmRemoveDrive()">
            Remove Drive...
        </button>
    </div>
</div>
<?php $remove_tab->endContent() ?>


<h2 class="mt-8">Greyhole Actions</h2>

<?php Tab::printTabs($tabs, 'page_action') ?>

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

<div id="modal-confirm-remove-drive" class="modal" tabindex="-1" role="dialog">
    <div class="modal-dialog" role="document">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">Remove Drive</h5>
                <button type="button" class="close" data-dismiss="modal" aria-label="Close">
                    <span aria-hidden="true">&times;</span>
                </button>
            </div>
            <div class="modal-body">
                <?php echo get_config_html(['name' => 'drive_is_available', 'display_name' => 'Is the specified drive still available?', 'type' => 'bool', 'onchange' => FALSE, 'help' => "If so, Greyhole will try to move all file copies that are only on this drive, onto your other drives."], TRUE, FALSE) ?>
            </div>
            <div class="modal-footer">
                <div id="remove-drive-preparing">
                    Loading... Please wait.
                </div>
                <div id="remove-drive-ready" class="d-none">
                    <button type="button" class="btn btn-danger" onclick="startRemoveDrive(this)">Remove Drive</button>
                    <button type="button" class="btn btn-secondary" data-dismiss="modal">Cancel</button>
                </div>
                <div id="remove-drive-done" class="d-none">
                    <button type="button" class="btn btn-secondary" onclick="$(this).text('Reloading...').prop('disabled', true); window.location='./'">Ok</button>
                </div>
            </div>
        </div>
    </div>
</div>
