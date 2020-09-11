
<?php
$os = 'Linux';
if (file_exists('/usr/bin/lsb_release')) {
    $os = exec("/usr/bin/lsb_release -si", $tmp, $return_var);
} elseif (file_exists(('/etc/os-release'))) {
    $os = exec("cat /etc/os-release | grep '^NAME=' | awk -F'\"' '{print $2}'");
} elseif (file_exists(('/etc/lsb-release'))) {
    $os = exec("cat /etc/lsb-release | grep '^DISTRIB_ID=' | awk -F'\"' '{print $2}'");
} elseif (file_exists(('/etc/debian_version'))) {
    $os = 'Debian';
} elseif (file_exists(('/etc/SuSe-release'))) {
    $os = 'SuSe';
} elseif (file_exists(('/etc/redhat-release'))) {
    $os = 'Red Hat';
}

$config = [
    'type' => 'group',
    'values' => [
        [
            'name' => 'db_host',
            'display_name' => "Host (name or IP)",
            'type' => 'string',
            'help' => "Hostname or IP address of your MySQL(-compatible) server. Normally 'localhost'",
            'onchange' => FALSE,
        ],
        [
            'name' => 'db_root_password',
            'display_name' => "MySQL root password",
            'type' => 'password',
            'help' => "Leave empty if no password is required to connect using the root user.",
            'onchange' => FALSE,
        ],
    ],
];
?>
<h2 class="mt-4 mb-4">Setup MySQL Database</h2>

<div>
    Ensure your MySQL server is set to start on boot (how to do that depends on your OS; <a href="https://www.google.com/search?q=start%20mysql%20on%20boot%20<?php echo urlencode($os) ?>" target="_blank">Google it</a> if needed).<br/>
    Then fill the form below.
</div>

<div class="mt-3">
    <div class="col">
        <?php echo get_config_html($config, NULL, 2) ?>
    </div>
</div>
