

<h3 class="mt-4 mb-4">Setup Samba</h3>

<div>
    Before you continue, make sure you have shares created on Samba, and that you are able to connect to those shares remotely (or locally, using <code>mount.cifs</code>).<br/>
    Of note: Samba uses its own users database; you'll need to create your user(s) using <code>smbpasswd -a</code> before you can connect to your shares.<br/>
    <br/>
    You can use the <code>Add Samba Share</code> and <code>Add Samba User</code> buttons below to create shares & users.<br/>
    Then continue in the <code>Required Samba Config</code> section.
</div>

<div class="row mt-3">
    <div class="col-12 col-md-6">
        <?php
        define('SKIP_GH_COLUMNS', TRUE);
        include 'web-app/views/samba_shares.php';
        ?>
    </div>
    <div class="col-12 col-md-6">
        <h2 class="mt-8">Samba Users</h2>
        <?php
        exec("/usr/bin/pdbedit -L | grep -v WARNING | grep -v 4294967295", $samba_users);
        ?>
        <?php echo get_config_html(['name' => 'samba_users', 'display_name' => 'Existing Samba users', 'type' => 'multi-string', 'onchange' => FALSE], $samba_users) ?>
        <button type="button" class="btn btn-primary mt-2" data-toggle="modal" data-target="#modal-add-samba-user">
            Add Samba User
        </button>
    </div>
</div>

<h2 class="mt-8">Required Samba Config</h2>

<div class="input_group mt-4">
    <?php
    $wide_links = exec("/usr/bin/testparm -sl --parameter-name='wide links' 2>/dev/null");
    $unix_extensions = exec("/usr/bin/testparm -sl --parameter-name='unix extensions' 2>/dev/null");
    $allow_insecure_wide_links = exec("/usr/bin/testparm -sl --parameter-name='allow insecure wide links' 2>/dev/null");
    ?>
    <?php echo get_config_html(['name' => 'smb.conf:[global]wide_links', 'display_name' => 'Wide links', 'type' => 'bool', 'help' => "Wide links needs to be enabled, or you won't be able to access your files on your Greyhole-enabled Samba shares.", 'onchange' => "checkSambaConfig(); config_value_changed(this)"], $wide_links == 'Yes') ?>
    <?php echo get_config_html(['name' => 'smb.conf:[global]unix_extensions', 'display_name' => 'Unix Extensions', 'type' => 'bool', 'help' => "Either you disable Unix Extensions, or enable Allow Insecure Wide Links below.", 'onchange' => "checkSambaConfig(); config_value_changed(this)"], $unix_extensions == 'Yes') ?>
    <?php echo get_config_html(['name' => 'smb.conf:[global]allow_insecure_wide_links', 'display_name' => 'Allow Insecure Wide Links', 'type' => 'bool', 'onchange' => "checkSambaConfig(); config_value_changed(this)"], $allow_insecure_wide_links == 'Yes') ?>
    <script>
        let last_known_config_hash, last_known_config_hash_samba;
    </script>
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
