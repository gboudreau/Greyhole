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

$gh_options_tab = new Tab('l2_smbconfig_general', 'Greyhole-required options');
$users_tab      = new Tab('l2_smbconfig_users', 'Users');
$tabs = [$gh_options_tab, $users_tab];
?>

<?php $gh_options_tab->startContent() ?>
<div class='input_group mt-4'>
    <?php
    $wide_links = exec("/usr/bin/testparm -sl --parameter-name='wide links' 2>/dev/null");
    $unix_extensions = exec("/usr/bin/testparm -sl --parameter-name='unix extensions' 2>/dev/null");
    $allow_insecure_wide_links = exec("/usr/bin/testparm -sl --parameter-name='allow insecure wide links' 2>/dev/null");
    ?>
    <?php echo get_config_html(['name' => 'smb.conf:[global]wide_links', 'display_name' => 'Wide links', 'type' => 'bool', 'help' => "Wide links needs to be enabled, or you won't be able to access your files on your Greyhole-enabled Samba shares.", 'onchange' => "checkSambaConfig(); config_value_changed(this)"], $wide_links == 'Yes') ?>
    <?php echo get_config_html(['name' => 'smb.conf:[global]unix_extensions', 'display_name' => 'Unix Extensions', 'type' => 'bool', 'help' => "Either you disable Unix Extensions, or enable Allow Insecure Wide Links below.", 'onchange' => "checkSambaConfig(); config_value_changed(this)"], $unix_extensions == 'Yes') ?>
    <?php echo get_config_html(['name' => 'smb.conf:[global]allow_insecure_wide_links', 'display_name' => 'Allow Insecure Wide Links', 'type' => 'bool', 'onchange' => "checkSambaConfig(); config_value_changed(this)"], $allow_insecure_wide_links == 'Yes') ?>
</div>
<?php $gh_options_tab->endContent() ?>

<?php $users_tab->startContent() ?>
<div class='input_group mt-4'>
    <?php
    exec("/usr/bin/pdbedit -L | grep -v WARNING | grep -v 4294967295", $samba_users);
    ?>
    <?php echo get_config_html(['name' => 'samba_users', 'display_name' => 'Existing Samba users', 'type' => 'multi-string', 'onchange' => FALSE], $samba_users) ?>
    <button type="button" class="btn btn-primary mt-2" data-toggle="modal" data-target="#modal-add-samba-user">
        Add Samba User
    </button>
</div>
<?php $users_tab->endContent() ?>


<h2 class="mt-8">Samba Config</h2>

<?php Tab::printTabs($tabs, 'page_smb_config') ?>

<div id="modal-add-samba-user" class="modal" tabindex="-1" role="dialog">
    <div class="modal-dialog" role="document">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">Add Samba User</h5>
                <button type="button" class="close" data-dismiss="modal" aria-label="Close">
                    <span aria-hidden="true">&times;</span>
                </button>
            </div>
            <div class="modal-body">
                <div class="mb-1">Username</div>
                <?php echo get_config_html(['name' => 'samba_username', 'type' => 'string', 'onchange' => FALSE], '') ?>
                <div class="mb-1">Password</div>
                <?php echo get_config_html(['name' => 'samba_password', 'type' => 'string', 'onchange' => FALSE], '') ?>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-primary" onclick="addSambaUser(this)">Create User</button>
                <button type="button" class="btn btn-secondary" data-dismiss="modal">Cancel</button>
            </div>
        </div>
    </div>
</div>
