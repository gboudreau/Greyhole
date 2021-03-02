<?php

$configs = [];

global $licensed;
if (!@$licensed && !defined('IS_INITIAL_SETUP')) {
    $configs[] = [
        'name' => 'License',
        'type' => 'group',
        'values' => [
            [
                'name' => 'donate_button_github',
                'display_name' => "Donate using Github",
                'type' => 'button',
                'href' => 'https://github.com/sponsors/gboudreau',
                'target' => '_blank',
                'value' => "Donate!",
                'help' => "Donate using a Github sponsorship (monthly)",
            ],
            [
                'name' => 'donate_button_paypal',
                'display_name' => "Donate using Paypal",
                'type' => 'button',
                'href' => 'https://www.greyhole.net/#donate',
                'target' => '_blank',
                'value' => "Donate!",
                'help' => "Or donate using Paypal (one-time)",
            ],
            [
                'name' => 'donate_email',
                'display_name' => "Donation email address",
                'type' => 'string',
                'value' => Settings::get('registered_email'),
                'help' => "Once you sent a donation, or if you already donated in the past, enter the email address you used for your donation here.",
                'onchange' => 'donationComplete(this)',
            ],
        ],
    ];
}

if (!defined('IS_INITIAL_SETUP')) {
    $configs[] = [
        'name' => 'Database connection',
        'type' => 'group',
        'values' => [
            [
                'name' => CONFIG_DB_HOST,
                'display_name' => "Host (name or IP address)",
                'type' => 'string',
                'help' => "Hostname or IP address of your MySQL(-compatible) server. Normally 'localhost'",
            ],
            [
                'name' => CONFIG_DB_USER,
                'display_name' => "Username",
                'type' => 'string',
                'help' => "Username used to connect to the above server. Normally 'greyhole_user'",
            ],
            [
                'name' => CONFIG_DB_PASS,
                'display_name' => "Password",
                'type' => 'string',
                'help' => "Password for the above user.",
            ],
            [
                'name' => CONFIG_DB_NAME,
                'display_name' => "Database (name)",
                'type' => 'string',
                'help' => "Database name used by Greyhole. Normally 'greyhole'",
            ],
        ],
    ];
}
$configs[] = [
    'name' => 'Server',
    'type' => 'group',
    'values' => [
        [
            'name' => CONFIG_TIMEZONE,
            'display_name' => "Timezone",
            'type' => 'timezone',
            'help' => "The timezone used for logs.",
        ],
    ]
];
$configs[] = [
    'name' => 'Notifications',
    'type' => 'group',
    'values' => [
        [
            'name' => CONFIG_EMAIL_TO,
            'display_name' => "Send notification emails to",
            'type' => 'string',
            'help' => "Will receive email reports for daily fsck, or when all drives are out of available space. When specifying no @hostname, the email will be delivered to the local mailbox of that user. Note: Make sure you have a MTA installed (sendmail, postfix, ...), or no emails will be sent!",
        ],
    ]
];
$configs[] = [
    'name' => 'Logging',
    'type' => 'group',
    'values' => [
        [
            'name' => CONFIG_GREYHOLE_LOG_FILE,
            'display_name' => "Log File",
            'type' => 'string',
            'help' => "Log file where the daemon will write. Use 'syslog' to log to (r)syslog.",
        ],
        [
            'name' => CONFIG_GREYHOLE_ERROR_LOG_FILE,
            'display_name' => "Error Log File (optional)",
            'type' => 'string',
            'help' => "If you define a greyhole_error_log_file, WARNING, ERROR and CRITICAL logs will be written there.",
        ],
        [
            'name' => CONFIG_LOG_LEVEL,
            'display_name' => "Log Level",
            'type' => 'toggles',
            'possible_values' => ['DEBUG' => 'DEBUG', 'INFO' => 'INFO', 'WARN' => 'WARN', 'ERROR' => 'ERROR'],
            'help' => "Log more (DEBUG) or less (ERROR) to the log file defined above.",
        ],
        [
            'name' => CONFIG_LOG_MEMORY_USAGE,
            'display_name' => "Log memory usage?",
            'type' => 'bool',
            'help' => "Log Greyhole memory usage on each log line? (Enable only when debugging a memory isssue.)",
        ],
    ]
];
$configs[] = [
    'name' => 'Copying',
    'type' => 'group',
    'values' => [
        [
            'name' => CONFIG_CALCULATE_MD5_DURING_COPY,
            'display_name' => "Calculate (MD5) hash during copy?",
            'type' => 'bool',
            'help' => "Calculate MD5 of new/changed files, while making file copies.",
        ],
        [
            'name' => CONFIG_PARALLEL_COPYING,
            'display_name' => "Create all file copies in parallel?",
            'type' => 'bool',
            'help' => "Create all file copies simultaneously, instead of sequentially.",
        ],
    ]
];
$configs[] = [
    'name' => 'Trash',
    'type' => 'group',
    'values' => [
        [
            'name' => CONFIG_DELETE_MOVES_TO_TRASH,
            'display_name' => "Move to trash deleted files?",
            'type' => 'bool',
            'help' => "The Trash (also called Attic or Recycle Bin), is used as a safeguard against human or machine errors. Greyhole can use the Trash for files that have been deleted. It is strongly recommended that you keep this setting ON; this will further protect your files.",
        ],
        [
            'name' => CONFIG_MODIFIED_MOVES_TO_TRASH,
            'display_name' => "Move to trash old versions of modified files?",
            'type' => 'bool',
            'help' => "Greyhole can use the Trash for files that have been modified. It is strongly recommended that you keep this setting ON; this will further protect your files.",
        ],
    ]
];
$configs[] = [
    'name' => 'Advanced',
    'type' => 'group',
    'values' => [
        [
            'name' => CONFIG_DAEMON_NICENESS,
            'display_name' => "Greyhole Daemon Niceness",
            'type' => 'toggles',
            'possible_values' => ['19' => '19 (most nice)', '15' => '15', '10' => '10', '5' => '5', '1' => '1', '0' => '0', '-1' => '-1', '-5' => '-5', '-10' => '-10', '-15' => '-15', '-19' => '-19 (least nice)'],
            'help' => "Niceness of the background daemon that handle most of the heavy lifting. The higher the number, the more 'nice' the daemon will be, i.e. the less resources it will get, versus other processes with a lower niceness.",
        ],
        [
            'name' => CONFIG_CHECK_FOR_OPEN_FILES,
            'display_name' => "Check for open files?",
            'type' => 'bool',
            'help' => "Look for other processes working on files on the Greyhole shares? Disable to get more speed, but this might break some files, if any application change your files while Greyhole tries to work on them.",
        ],
        [
            'name' => CONFIG_DF_CACHE_TIME,
            'display_name' => "Cache time for `df`",
            'suffix' => "seconds",
            'type' => 'integer',
            'help' => "How long should free space calculations be cached (in seconds). When selecting drives using their available / free space, the last cached value will be used. Use 0 to disable caching (not recommended). Default is 15",
        ],
        [
            'name' => CONFIG_MEMORY_LIMIT,
            'display_name' => "Memory limit",
            'type' => 'bytes',
            'shorthand' => TRUE,
            'help' => "Maximum amount of memory that Greyhole can consume while running. This can be higher than the global php.ini memory limit. Default is 512M. It is NOT advisable to lower the memory limit.",
        ],
        [
            'name' => CONFIG_EXECUTED_TASKS_RETENTION,
            'display_name' => "Past (executed) tasks retention",
            'suffix' => "days",
            'type' => 'integer',
            'help' => "How long should executed tasks be kept in the database, after having been executed. Those are strictly for debugging purposes; they serve no other purposes. Enter a number of days, or 'forever'. The default is 60 days.",
        ],
        [
            'name' => CONFIG_CHECK_SP_SCHEDULE,
            'display_name' => "Schedule for Storage Pool Drives checks (format: *:mi or hh:mi)",
            'type' => 'string',
            'help' => "If you'd like you drives to sleep when inactive, setting a schedule here will prevent the Greyhole daemon from keeping your drives awake by checking them every 10s. Specify the time(s) when Greyhole is allowed to check storage pool drives. Supported format: '*:mi' (i.e. once per hour) and 'hh:mi' (once per day)",
        ],
    ]
];
$configs[] = [
    'name' => 'Ignored...',
    'type' => 'group',
    'values' => [
        [
            'name' => CONFIG_IGNORED_FILES,
            'display_name' => "...files",
            'type' => 'multi-string',
            'help' => "Files that match the patterns above will be ignored by Greyhole. They will stay in the landing zone indefinitely, so be careful on what you define here. 'ignored_files' is matched against the file name only."
        ],
        [
            'name' => CONFIG_IGNORED_FOLDERS,
            'display_name' => "...folders",
            'type' => 'multi-string',
            'help' => "'ignored_folders' is matched against the concatenation of the share name and the full path to the file (without the filename), eg: Videos/Movies/HD/"
        ],
    ]
];

$drive_selection = (object) [
    'name' => 'Drive Selection',
    'type' => 'group',
    'values' => [],
];
$configs[] = $drive_selection;

$possible_values_group_names = ['' => ''];
foreach (Config::get(CONFIG_DRIVE_SELECTION_GROUPS) as $name => $g) {
    $drive_selection->values[] = [
        'display_name' => "Group \"$name\"",
        'name' => CONFIG_DRIVE_SELECTION_GROUPS . "[$name]",
        'type' => 'sp_drives',
        'current_value' => $g,
    ];
    $possible_values_group_names[$name] = $name;
}

$is_forced = FALSE;
$ds_groups = Config::get(CONFIG_DRIVE_SELECTION_ALGORITHM);
foreach ($ds_groups as $ds_group) {
    if ($ds_group->is_forced) {
        $is_forced = TRUE;
    }
}

$drive_selection->values[] = [
    'display_name' => "Drive Selection Algorithm",
    'name' => CONFIG_DRIVE_SELECTION_ALGORITHM,
    'type' => 'toggles',
    'possible_values' => ['most_available_space' => 'Most available space (equal free space)', 'least_used_space' => "Least used space (equal used space)"],
    'current_value' => first($ds_groups)->selection_algorithm,
];

$drive_selection->values[] = [
    'display_name' => "Use (force) groups?",
    'name' => CONFIG_DRIVE_SELECTION_ALGORITHM . "_forced",
    'type' => 'bool',
    'current_value' => $is_forced,
];

$possible_values_num_drives = [];
$possible_values_num_drives[''] = '';
for ($i=1; $i<count(Config::storagePoolDrives()); $i++) {
    $possible_values_num_drives[(string) $i] = $i;
}
$possible_values_num_drives['all'] = 'All';

if ($is_forced) {
    for ($i=0; $i<min(count(Config::storagePoolDrives()), 10); $i++) {
        $prefix = ['1st', '2nd', '3rd', '4th', '5th', '6th', '7th', '8th', '9th', '10th'][$i];
        $drive_selection->values[] = [
            'display_name' => "$prefix",
            'name' => CONFIG_DRIVE_SELECTION_ALGORITHM . "_forced[" . $i . "][num]",
            'type' => 'select',
            'possible_values' => $possible_values_num_drives,
            'current_value' => !empty($ds_groups[$i]) ? $ds_groups[$i]->num_drives_config : '',
            'prefix' => 'pick',
            'suffix' => 'drive(s) from group',
            'glue' => 'next',
            'class' => 'forced_toggleable',
        ];
        $drive_selection->values[] = [
            'glue' => 'previous',
            'name' => CONFIG_DRIVE_SELECTION_ALGORITHM . "_forced[" . $i . "][group]",
            'type' => 'select',
            'possible_values' => $possible_values_group_names,
            'current_value' => !empty($ds_groups[$i]) ? $ds_groups[$i]->group_name : '',
        ];
    }
}

if (@$licensed) {
    $configs[] = [
        'name' => 'License',
        'type' => 'group',
        'values' => [
            [
                'name' => 'donate_email',
                'display_name' => "Donation Registered",
                'type' => 'string',
                'current_value' => Settings::get('registered_email'),
                'help' => "Thank you for your donation!",
                'onchange' => FALSE,
            ],
        ],
    ];
}

$configs = array_map(
    function($el) {
        return (object) $el;
    },
    $configs
);
