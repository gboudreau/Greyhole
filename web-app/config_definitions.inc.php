<?php

$configs = [
    [
        'name' => 'Database connexion',
        'type' => 'group',
        'values' => [
            [
                'name' => CONFIG_DB_HOST,
                'display_name' => "Host (hostname or IP address)",
                'type' => 'string',
            ],
            [
                'name' => CONFIG_DB_USER,
                'display_name' => "Username",
                'type' => 'string',
            ],
            [
                'name' => CONFIG_DB_PASS,
                'display_name' => "Password",
                'type' => 'string',
            ],
            [
                'name' => CONFIG_DB_NAME,
                'display_name' => "Database (name)",
                'type' => 'string',
            ],
        ],
    ],
    [
        'name' => 'Notifications',
        'type' => 'group',
        'values' => [
            [
                'name' => CONFIG_EMAIL_TO,
                'display_name' => "Send notification emails to",
                'type' => 'string',
            ],
        ]
    ],
    [
        'name' => 'Logging',
        'type' => 'group',
        'values' => [
            [
                'name' => CONFIG_GREYHOLE_LOG_FILE,
                'display_name' => "Log File",
                'type' => 'string',
            ],
            [
                'name' => CONFIG_GREYHOLE_ERROR_LOG_FILE,
                'display_name' => "Error Log File (optional)",
                'type' => 'string',
            ],
            [
                'name' => CONFIG_LOG_LEVEL,
                'display_name' => "Log Level",
                'type' => 'toggles',
                'possible_values' => ['DEBUG' => 'DEBUG', 'INFO' => 'INFO', 'WARN' => 'WARN', 'ERROR' => 'ERROR'],
            ],
            [
                'name' => CONFIG_LOG_MEMORY_USAGE,
                'display_name' => "Log memory usage?",
                'type' => 'bool',
            ],
        ]
    ],
    [
        'name' => 'Server',
        'type' => 'group',
        'values' => [
            [
                'name' => CONFIG_TIMEZONE,
                'display_name' => "Timezone",
                'type' => 'timezone',
            ],
        ]
    ],
    [
        'name' => 'Copying',
        'type' => 'group',
        'values' => [
            [
                'name' => CONFIG_CALCULATE_MD5_DURING_COPY,
                'display_name' => "Calculate (MD5) hash during copy?",
                'type' => 'bool',
            ],
            [
                'name' => CONFIG_PARALLEL_COPYING,
                'display_name' => "Create all file copies in parallel?",
                'type' => 'bool',
            ],
        ]
    ],
    [
        'name' => 'Trash',
        'type' => 'group',
        'values' => [
            [
                'name' => CONFIG_DELETE_MOVES_TO_TRASH,
                'display_name' => "Move to trash deleted files?",
                'type' => 'bool',
            ],
            [
                'name' => CONFIG_MODIFIED_MOVES_TO_TRASH,
                'display_name' => "Move to trash old versions of modified files?",
                'type' => 'bool',
            ],
        ]
    ],
    [
        'name' => 'Advanced',
        'type' => 'group',
        'values' => [
            [
                'name' => CONFIG_DAEMON_NICENESS,
                'display_name' => "Greyhole Daemon Niceness",
                'type' => 'toggles',
                'possible_values' => ['19' => '19 (most nice)', '15' => '15', '10' => '10', '5' => '5', '1' => '1', '0' => '0', '-1' => '-1', '-5' => '-5', '-10' => '-10', '-15' => '-15', '-19' => '-19 (least nice)'],
            ],
            [
                'name' => CONFIG_CHECK_FOR_OPEN_FILES,
                'display_name' => "Check for open files?",
                'type' => 'bool',
            ],
            [
                'name' => CONFIG_DF_CACHE_TIME,
                'display_name' => "Cache time for `df`",
                'suffix' => "seconds",
                'type' => 'integer',
            ],
            [
                'name' => CONFIG_MEMORY_LIMIT,
                'display_name' => "Memory limit",
                'type' => 'bytes',
                'shorthand' => TRUE,
            ],
            [
                'name' => CONFIG_EXECUTED_TASKS_RETENTION,
                'display_name' => "Past (executed) tasks retention",
                'suffix' => "days",
                'type' => 'integer',
            ],
            [
                'name' => CONFIG_CHECK_SP_SCHEDULE,
                'display_name' => "Schedule for Storage Pool Drives checks (format: *:mi or hh:mi)",
                'type' => 'string',
            ],
        ]
    ],
    [
        'name' => 'Ignored...',
        'type' => 'group',
        'values' => [
            [
                'name' => CONFIG_IGNORED_FILES,
                'display_name' => "...files",
                'type' => 'multi-string',
            ],
            [
                'name' => CONFIG_IGNORED_FOLDERS,
                'display_name' => "...folders",
                'type' => 'multi-string',
            ],
        ]
    ],
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
    'current_value' => $is_forced ? 'yes' : 'no',
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

$configs = array_map(
    function($el) {
        return (object) $el;
    },
    $configs
);
