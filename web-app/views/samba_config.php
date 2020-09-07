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
    'smb-general' => 'Greyhole-required options',
    'smb-users' => 'Users',
];
?>

<h2 class="mt-8">Samba Config</h2>

<ul class="nav nav-tabs" id="myTabsSamba" role="tablist" data-name="pagesmb">
    <?php $first = empty($_GET['pagesmb']); foreach ($configs as $id => $name) : $active = $first || @$_GET['pagesmb'] == 'id_' . $id . '_tab'; if ($active) $selected_tab = $id; ?>
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
<div class="tab-content" id="myTabContentSamba">
    <div class="tab-pane fade <?php if ($selected_tab == 'smb-general') echo 'show active' ?>" id="id_smb-general" role="tabpanel" aria-labelledby="id-smb-general-tab">
        <div class='input_group mt-4'>
            <?php
            $wide_links = exec("/usr/bin/testparm -sl --parameter-name='wide links' 2>/dev/null");
            $unix_extensions = exec("/usr/bin/testparm -sl --parameter-name='unix extensions' 2>/dev/null");
            $allow_insecure_wide_links = exec("/usr/bin/testparm -sl --parameter-name='allow insecure wide links' 2>/dev/null");
            ?>
            <?php echo get_config_html(['name' => 'smb.conf:[global]wide_links', 'display_name' => 'Wide links', 'type' => 'bool', 'help' => "Wide links needs to be enabled, or you won't be able to access your files on your Greyhole-enabled Samba shares."], $wide_links == 'Yes') ?>
            <?php echo get_config_html(['name' => 'smb.conf:[global]unix_extensions', 'display_name' => 'Unix Extensions', 'type' => 'bool', 'help' => "Either you disable Unix Extensions, or enable Allow Insecure Wide Links below."], $unix_extensions == 'Yes') ?>
            <?php echo get_config_html(['name' => 'smb.conf:[global]allow_insecure_wide_links', 'display_name' => 'Allow Insecure Wide Links', 'type' => 'bool'], $allow_insecure_wide_links == 'Yes') ?>
        </div>
    </div>
    <div class="tab-pane fade <?php if ($selected_tab == 'smb-users') echo 'show active' ?>" id="id_smb-users" role="tabpanel" aria-labelledby="id-smb-users-tab">
        <div class='input_group mt-4'>
            <?php
            exec("/usr/bin/pdbedit -L | grep -v WARNING | grep -v 4294967295", $samba_users);
            ?>
            <?php echo get_config_html(['name' => 'samba_users', 'display_name' => 'Existing Samba users', 'type' => 'multi-string', 'onchange' => FALSE], $samba_users) ?>
            <button type="button" class="btn btn-primary mt-2" data-toggle="modal" data-target="#modal-add-samba-user">
                Add Samba User
            </button>
        </div>
    </div>
</div>
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
