<?php
/*
Copyright 2013-2014 Guillaume Boudreau

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

chdir(__DIR__ . '/../..');

if (!empty($_GET['path'])) {
    include('web-app/index.php');
    exit();
}
include('includes/common.php');
include('includes/CLI/CommandLineHelper.php'); // Command line helper (abstract classes, command line definitions & parsing, Runners, etc.)
include('includes/DaemonRunner.php');

error_reporting(E_ALL);
restore_error_handler();

ConfigHelper::parse();
DB::connect();

header('Content-Type: text/html; charset=utf8');
setlocale(LC_CTYPE, "en_US.UTF-8");

function phe($string) {
    echo he($string);
}
function he($string) {
    return htmlentities($string, ENT_QUOTES|ENT_HTML401);
}
?>

<!doctype html>
<html lang="en">
<head>
    <!-- Required meta tags -->
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, shrink-to-fit=no">

    <!-- Bootstrap CSS -->
    <link rel="stylesheet" href="https://stackpath.bootstrapcdn.com/bootstrap/4.5.2/css/bootstrap.min.css" integrity="sha384-JcKb8q3iqJ61gNV9KGb8thSsNjpSL0n8PARn9HuZOnIxN0hoP+VmmDGMN5t9UJ0Z" crossorigin="anonymous">
    <script src="https://cdn.jsdelivr.net/npm/chart.js@2.9.3/dist/Chart.min.js" integrity="sha256-R4pqcOYV8lt7snxMQO/HSbVCFRPMdrhAFMH+vr9giYI=" crossorigin="anonymous"></script>
    <script type="text/javascript" src="https://www.google.com/jsapi"></script>

    <title>Greyhole Admin</title>
</head>
<body>

<div class="container-fluid">

<h2>Storage Pool Drives</h2>

<div class="row">
    <div class="col-sm-12 col-lg-6">
        <table cellspacing="0" cellpadding="6">
            <thead>
                <tr>
                <th>Path</th>
                <th>Total</th>
                <th>Used</th>
                <th>Free</th>
                <th>Trash</th>
                <th>Possible</th>
                <th>Min. free space</th>
            </tr>
            </thead>
            <tbody>
                <?php
                $stats = StatsCliRunner::get_stats();
                $dataset_used = [];
                $dataset_trash = [];
                $dataset_free = [];
                foreach ($stats as $sp_drive => $stat) {
                    if ($sp_drive == 'Total') {
                        continue;
                    }
                    $dataset_used[] = $stat->used_space;
                    $dataset_trash[] = $stat->trash_size;
                    $dataset_free[] = $stat->free_space;
                }
                $drives = array_keys($stats);
                $dataset = array_merge($dataset_used, $dataset_trash, $dataset_free);
                ?>
                <?php foreach ($stats as $sp_drive => $stat) : ?>
                    <?php if ($sp_drive == 'Total') continue; ?>
                    <tr>
                        <td><?php phe($sp_drive) ?></td>
                        <?php if (empty($stat->total_space)) : ?>
                            <td colspan="5">Offline</td>
                        <?php else : ?>
                            <td><?php echo bytes_to_human($stat->total_space*1024) ?></td>
                            <td><?php echo bytes_to_human($stat->used_space*1024) ?></td>
                            <td><?php echo bytes_to_human($stat->free_space*1024) ?></td>
                            <td><?php echo bytes_to_human($stat->trash_size*1024) ?></td>
                            <td><?php echo bytes_to_human($stat->potential_available_space*1024) ?></td>
                        <?php endif; ?>
                        <td>
                            <?php echo get_config_html(['name' => CONFIG_MIN_FREE_SPACE_POOL_DRIVE . "[$sp_drive]", 'type' => 'kbytes'], Config::get(CONFIG_MIN_FREE_SPACE_POOL_DRIVE, $sp_drive)) ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <div class="col-sm-12 col-lg-6">
        <?php $stat = $stats['Total']; ?>
        <div class="chart-container">
            <canvas id="chart_storage_pool" width="200" height="200"></canvas>
        </div>
        <script>
            window.chartColors = {
                red: 'rgba(222, 66, 91, 1)',
                orange: 'rgba(255, 159, 64, 1)',
                yellow: 'rgba(255, 236, 152, 1)',
                green: 'rgba(72, 143, 49, 1)',
                blue: 'rgba(54, 162, 235, 1)',
                purple: 'rgba(153, 102, 255, 1)',
                grey: 'rgba(201, 203, 207, 1)',
            };
            window.chartColorsSemi = {
                red: 'rgba(222, 66, 91, 0.6)',
                orange: 'rgba(255, 159, 64, 0.6)',
                yellow: 'rgba(255, 236, 152, 0.6)',
                green: 'rgba(72, 143, 49, 0.6)',
                blue: 'rgba(54, 162, 235, 0.6)',
                purple: 'rgba(153, 102, 255, 0.6)',
                grey: 'rgba(201, 203, 207, 0.6)',
            };
            var total = <?php echo json_encode($stat->used_space + $stat->trash_size + $stat->free_space) ?>;
            var ctx = document.getElementById('chart_storage_pool').getContext('2d');
            new Chart(ctx, {
                type: 'pie',
                data: {
                    datasets: [
                        {
                            // "Sum" dataset needs to appear first, for Leged to appear correctly
                            weight: 0,
                            data: <?php echo json_encode([$stat->used_space, $stat->trash_size, $stat->free_space]) ?>,
                            backgroundColor: [
                                window.chartColors.red,
                                window.chartColors.yellow,
                                window.chartColors.green
                            ],
                            labels: [
                                'Used: <?php echo bytes_to_human($stat->used_space*1024, FALSE) ?>',
                                'Trash: <?php echo bytes_to_human($stat->trash_size*1024, FALSE) ?>',
                                'Free: <?php echo bytes_to_human($stat->free_space*1024, FALSE) ?>'
                            ],
                        },
                        {
                            weight: 50,
                            data: <?php echo json_encode($dataset) ?>,
                            backgroundColor: [
                                <?php
                                foreach ($dataset_used as $t) {
                                    echo "window.chartColorsSemi.red,";
                                }
                                foreach ($dataset_trash as $t) {
                                    echo "window.chartColorsSemi.yellow,";
                                }
                                foreach ($dataset_free as $t) {
                                    echo "window.chartColorsSemi.green,";
                                }
                                ?>
                            ],
                            labels: [
                                <?php
                                foreach ($dataset_used as $i => $v) {
                                    echo json_encode($drives[$i] . " Used: " . bytes_to_human($v*1024, FALSE)) . ",";
                                }
                                foreach ($dataset_trash as $i => $v) {
                                    echo json_encode($drives[$i] . " Trash: " . bytes_to_human($v*1024, FALSE)) . ",";
                                }
                                foreach ($dataset_free as $i => $v) {
                                    echo json_encode($drives[$i] . " Free: " . bytes_to_human($v*1024, FALSE)) . ",";
                                }
                                ?>
                            ]
                        },
                        {
                            weight: 50,
                            data: <?php echo json_encode([$stat->used_space, $stat->trash_size, $stat->free_space]) ?>,
                            backgroundColor: [
                                window.chartColors.red,
                                window.chartColors.yellow,
                                window.chartColors.green
                            ],
                            labels: [
                                'Used: <?php echo bytes_to_human($stat->used_space*1024, FALSE) ?>',
                                'Trash: <?php echo bytes_to_human($stat->trash_size*1024, FALSE) ?>',
                                'Free: <?php echo bytes_to_human($stat->free_space*1024, FALSE) ?>'
                            ],
                        },
                    ],
                    labels: [
                        'Used: <?php echo bytes_to_human($stat->used_space*1024, FALSE) ?>',
                        'Trash: <?php echo bytes_to_human($stat->trash_size*1024, FALSE) ?>',
                        'Free: <?php echo bytes_to_human($stat->free_space*1024, FALSE) ?>'
                    ]
                },
                options: {
                    cutoutPercentage: 20,
                    responsive: true,
                    responsiveAnimationDuration: 400,
                    legend: {
                        position: 'right',
                    },
                    tooltips: {
                        callbacks: {
                            label: function(tooltipItem, data) {
                                var label = data.datasets[tooltipItem.datasetIndex].labels[tooltipItem.index] || '';
                                if (label) {
                                    label += ' = ';
                                }
                                let value = data.datasets[tooltipItem.datasetIndex].data[tooltipItem.index];
                                var percentage = Math.round(value/total*100);
                                label += percentage + "%";
                                return label;
                            }
                        }
                    }
                }
            });
        </script>
    </div>
</div>

<h2>Samba Shares</h2>
<?php
$possible_values_num_copies = [];
for ($i=1; $i<count(Config::storagePoolDrives()); $i++) {
    $possible_values_num_copies[(string) $i] = $i;
}
$possible_values_num_copies['max'] = 'Max';
?>
<div class="row">
    <div class="col-sm-12 col-lg-6">
        <ul>
            <?php foreach (SharesConfig::getShares() as $share_name => $share_options) : ?>
                <li>
                    <strong><?php phe($share_name) ?></strong><br/>
                    Landing zone: <code><?php phe($share_options['landing_zone']) ?></code><br/>
                    <?php echo get_config_html(['name' => CONFIG_NUM_COPIES . "[$share_name]", 'display_name' => "Number of file copies", 'type' => 'select', 'possible_values' => $possible_values_num_copies], $share_options['num_copies'] == count(Config::storagePoolDrives()) ? 'max' : $share_options['num_copies'] , FALSE) ?>
                </li>
            <?php endforeach; ?>
            <li>@todo show other Samba shares (not defined in greyhole.conf)?</li>
        </ul>
    </div>
    <div class="col-sm-12 col-lg-6">
        <?php
        $q = "SELECT size, depth, share AS file_path FROM du_stats WHERE depth = 1 ORDER BY size DESC";
        $rows = DB::getAll($q);

        $dataset = [];
        $labels = [];
        $colors = [];
        $avail_colors = ['#003f5c','#58508d','#bc5090','#ff6361','#ffa600'];
        foreach ($rows as $i => $row) {
            $dataset[] = (float) $row->size;
            $labels[] = "$row->file_path: " . bytes_to_human($row->size, FALSE);
            $colors[] = $avail_colors[$i % count($avail_colors)];
        }
        ?>
        <style>
            .chart-container {
                position: relative;
                width:95vw;
            }
            @media (min-width: 992px) {
                .chart-container {
                    width:50vw;
                    max-width: 700px;
                    padding-right: 45px;
                }
            }
        </style>
        <div class="chart-container">
            <canvas id="chart_shares_usage" width="200" height="200"></canvas>
        </div>
        <script>
            var ctx = document.getElementById('chart_shares_usage').getContext('2d');
            new Chart(ctx, {
                type: 'pie',
                data: {
                    datasets: [
                        {
                            data: <?php echo json_encode($dataset) ?>,
                            backgroundColor: [
                                <?php
                                foreach ($colors as $c) {
                                    echo "'$c',";
                                }
                                ?>
                            ],
                        },
                    ],
                    labels: <?php echo json_encode($labels) ?>
                },
                options: {
                    cutoutPercentage: 20,
                    responsive: true,
                    responsiveAnimationDuration: 400,
                    legend: {
                        position: 'right',
                    },
                    tooltips: {
                        callbacks: {
                            label: function(tooltipItem, data) {
                                var label = data.labels[tooltipItem.index] || '';
                                return label;
                            }
                        }
                    }
                }
            });
        </script>
    </div>
</div>

<h2>Greyhole Config</h2>

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
$possible_values_num_drives[''] = '0';
for ($i=1; $i<=count(Config::storagePoolDrives()); $i++) {
    $possible_values_num_drives[(string) $i] = $i;
}
$possible_values_num_drives['all'] = 'All';

if ($is_forced) {
    for ($i=0; $i<count($possible_values_group_names); $i++) {
        $prefix = ['1st', '2nd', '3rd', '4th', '5th', '6th', '7th', '8th', '9th', '10th'][$i];
        $drive_selection->values[] = [
            'display_name' => "$prefix",
            'name' => CONFIG_DRIVE_SELECTION_ALGORITHM . "_forced[0][num]",
            'type' => 'select',
            'possible_values' => $possible_values_num_drives,
            'current_value' => !empty($ds_groups[$i]) ? $ds_groups[$i]->num_drives_config : '',
            'glue' => 'next',
            'prefix' => 'pick',
            'suffix' => 'drive(s) from group',
        ];
        $drive_selection->values[] = [
            'glue' => 'previous',
            'name' => CONFIG_DRIVE_SELECTION_ALGORITHM . "_forced[0][group]",
            'type' => 'select',
            'possible_values' => $possible_values_group_names,
            'current_value' => !empty($ds_groups[$i]) ? $ds_groups[$i]->group_name : '',
        ];
    }
}

$configs[] = $drive_selection;

$configs = array_map(function($el) { return (object) $el; }, $configs);

function get_config_html($config, $current_value = NULL, $fixed_width_label = TRUE) {
    $config = (object) $config;
    $html = '';
    if ($config->type == 'group') {
        $html .= "<div class='input_group'>";
        $html .= "<h4>" . he($config->name) . '</h4>';
        foreach ($config->values as $config) {
            $html .= get_config_html($config);
        }
        $html .= "</div>";
        return $html;
    }

    if ($config->type == 'timezone') {
        $config->type = 'select';
        $config->possible_values = [];
        if (!empty(ini_get('date.timezone'))) {
            $config->possible_values[''] = 'Use php.ini value (currently ' . ini_get('date.timezone') . ')';
        }
        $config->possible_values = array_merge($config->possible_values, ['Africa/Abidjan' => 'Africa/Abidjan', 'Africa/Accra' => 'Africa/Accra', 'Africa/Addis_Ababa' => 'Africa/Addis_Ababa', 'Africa/Algiers' => 'Africa/Algiers', 'Africa/Asmara' => 'Africa/Asmara', 'Africa/Asmera' => 'Africa/Asmera', 'Africa/Bamako' => 'Africa/Bamako', 'Africa/Bangui' => 'Africa/Bangui', 'Africa/Banjul' => 'Africa/Banjul', 'Africa/Bissau' => 'Africa/Bissau', 'Africa/Blantyre' => 'Africa/Blantyre', 'Africa/Brazzaville' => 'Africa/Brazzaville', 'Africa/Bujumbura' => 'Africa/Bujumbura', 'Africa/Cairo' => 'Africa/Cairo', 'Africa/Casablanca' => 'Africa/Casablanca', 'Africa/Ceuta' => 'Africa/Ceuta', 'Africa/Conakry' => 'Africa/Conakry', 'Africa/Dakar' => 'Africa/Dakar', 'Africa/Dar_es_Salaam' => 'Africa/Dar_es_Salaam', 'Africa/Djibouti' => 'Africa/Djibouti', 'Africa/Douala' => 'Africa/Douala', 'Africa/El_Aaiun' => 'Africa/El_Aaiun', 'Africa/Freetown' => 'Africa/Freetown', 'Africa/Gaborone' => 'Africa/Gaborone', 'Africa/Harare' => 'Africa/Harare', 'Africa/Johannesburg' => 'Africa/Johannesburg', 'Africa/Juba' => 'Africa/Juba', 'Africa/Kampala' => 'Africa/Kampala', 'Africa/Khartoum' => 'Africa/Khartoum', 'Africa/Kigali' => 'Africa/Kigali', 'Africa/Kinshasa' => 'Africa/Kinshasa', 'Africa/Lagos' => 'Africa/Lagos', 'Africa/Libreville' => 'Africa/Libreville', 'Africa/Lome' => 'Africa/Lome', 'Africa/Luanda' => 'Africa/Luanda', 'Africa/Lubumbashi' => 'Africa/Lubumbashi', 'Africa/Lusaka' => 'Africa/Lusaka', 'Africa/Malabo' => 'Africa/Malabo', 'Africa/Maputo' => 'Africa/Maputo', 'Africa/Maseru' => 'Africa/Maseru', 'Africa/Mbabane' => 'Africa/Mbabane', 'Africa/Mogadishu' => 'Africa/Mogadishu', 'Africa/Monrovia' => 'Africa/Monrovia', 'Africa/Nairobi' => 'Africa/Nairobi', 'Africa/Ndjamena' => 'Africa/Ndjamena', 'Africa/Niamey' => 'Africa/Niamey', 'Africa/Nouakchott' => 'Africa/Nouakchott', 'Africa/Ouagadougou' => 'Africa/Ouagadougou', 'Africa/Porto-Novo' => 'Africa/Porto-Novo', 'Africa/Sao_Tome' => 'Africa/Sao_Tome', 'Africa/Timbuktu' => 'Africa/Timbuktu', 'Africa/Tripoli' => 'Africa/Tripoli', 'Africa/Tunis' => 'Africa/Tunis', 'Africa/Windhoek' => 'Africa/Windhoek', 'America/Adak' => 'America/Adak', 'America/Anchorage' => 'America/Anchorage', 'America/Anguilla' => 'America/Anguilla', 'America/Antigua' => 'America/Antigua', 'America/Araguaina' => 'America/Araguaina', 'America/Argentina/Buenos_Aires' => 'America/Argentina/Buenos_Aires', 'America/Argentina/Catamarca' => 'America/Argentina/Catamarca', 'America/Argentina/ComodRivadavia' => 'America/Argentina/ComodRivadavia', 'America/Argentina/Cordoba' => 'America/Argentina/Cordoba', 'America/Argentina/Jujuy' => 'America/Argentina/Jujuy', 'America/Argentina/La_Rioja' => 'America/Argentina/La_Rioja', 'America/Argentina/Mendoza' => 'America/Argentina/Mendoza', 'America/Argentina/Rio_Gallegos' => 'America/Argentina/Rio_Gallegos', 'America/Argentina/Salta' => 'America/Argentina/Salta', 'America/Argentina/San_Juan' => 'America/Argentina/San_Juan', 'America/Argentina/San_Luis' => 'America/Argentina/San_Luis', 'America/Argentina/Tucuman' => 'America/Argentina/Tucuman', 'America/Argentina/Ushuaia' => 'America/Argentina/Ushuaia', 'America/Aruba' => 'America/Aruba', 'America/Asuncion' => 'America/Asuncion', 'America/Atikokan' => 'America/Atikokan', 'America/Atka' => 'America/Atka', 'America/Bahia' => 'America/Bahia', 'America/Bahia_Banderas' => 'America/Bahia_Banderas', 'America/Barbados' => 'America/Barbados', 'America/Belem' => 'America/Belem', 'America/Belize' => 'America/Belize', 'America/Blanc-Sablon' => 'America/Blanc-Sablon', 'America/Boa_Vista' => 'America/Boa_Vista', 'America/Bogota' => 'America/Bogota', 'America/Boise' => 'America/Boise', 'America/Buenos_Aires' => 'America/Buenos_Aires', 'America/Cambridge_Bay' => 'America/Cambridge_Bay', 'America/Campo_Grande' => 'America/Campo_Grande', 'America/Cancun' => 'America/Cancun', 'America/Caracas' => 'America/Caracas', 'America/Catamarca' => 'America/Catamarca', 'America/Cayenne' => 'America/Cayenne', 'America/Cayman' => 'America/Cayman', 'America/Chicago' => 'America/Chicago', 'America/Chihuahua' => 'America/Chihuahua', 'America/Coral_Harbour' => 'America/Coral_Harbour', 'America/Cordoba' => 'America/Cordoba', 'America/Costa_Rica' => 'America/Costa_Rica', 'America/Creston' => 'America/Creston', 'America/Cuiaba' => 'America/Cuiaba', 'America/Curacao' => 'America/Curacao', 'America/Danmarkshavn' => 'America/Danmarkshavn', 'America/Dawson' => 'America/Dawson', 'America/Dawson_Creek' => 'America/Dawson_Creek', 'America/Denver' => 'America/Denver', 'America/Detroit' => 'America/Detroit', 'America/Dominica' => 'America/Dominica', 'America/Edmonton' => 'America/Edmonton', 'America/Eirunepe' => 'America/Eirunepe', 'America/El_Salvador' => 'America/El_Salvador', 'America/Ensenada' => 'America/Ensenada', 'America/Fort_Nelson' => 'America/Fort_Nelson', 'America/Fort_Wayne' => 'America/Fort_Wayne', 'America/Fortaleza' => 'America/Fortaleza', 'America/Glace_Bay' => 'America/Glace_Bay', 'America/Godthab' => 'America/Godthab', 'America/Goose_Bay' => 'America/Goose_Bay', 'America/Grand_Turk' => 'America/Grand_Turk', 'America/Grenada' => 'America/Grenada', 'America/Guadeloupe' => 'America/Guadeloupe', 'America/Guatemala' => 'America/Guatemala', 'America/Guayaquil' => 'America/Guayaquil', 'America/Guyana' => 'America/Guyana', 'America/Halifax' => 'America/Halifax', 'America/Havana' => 'America/Havana', 'America/Hermosillo' => 'America/Hermosillo', 'America/Indiana/Indianapolis' => 'America/Indiana/Indianapolis', 'America/Indiana/Knox' => 'America/Indiana/Knox', 'America/Indiana/Marengo' => 'America/Indiana/Marengo', 'America/Indiana/Petersburg' => 'America/Indiana/Petersburg', 'America/Indiana/Tell_City' => 'America/Indiana/Tell_City', 'America/Indiana/Vevay' => 'America/Indiana/Vevay', 'America/Indiana/Vincennes' => 'America/Indiana/Vincennes', 'America/Indiana/Winamac' => 'America/Indiana/Winamac', 'America/Indianapolis' => 'America/Indianapolis', 'America/Inuvik' => 'America/Inuvik', 'America/Iqaluit' => 'America/Iqaluit', 'America/Jamaica' => 'America/Jamaica', 'America/Jujuy' => 'America/Jujuy', 'America/Juneau' => 'America/Juneau', 'America/Kentucky/Louisville' => 'America/Kentucky/Louisville', 'America/Kentucky/Monticello' => 'America/Kentucky/Monticello', 'America/Knox_IN' => 'America/Knox_IN', 'America/Kralendijk' => 'America/Kralendijk', 'America/La_Paz' => 'America/La_Paz', 'America/Lima' => 'America/Lima', 'America/Los_Angeles' => 'America/Los_Angeles', 'America/Louisville' => 'America/Louisville', 'America/Lower_Princes' => 'America/Lower_Princes', 'America/Maceio' => 'America/Maceio', 'America/Managua' => 'America/Managua', 'America/Manaus' => 'America/Manaus', 'America/Marigot' => 'America/Marigot', 'America/Martinique' => 'America/Martinique', 'America/Matamoros' => 'America/Matamoros', 'America/Mazatlan' => 'America/Mazatlan', 'America/Mendoza' => 'America/Mendoza', 'America/Menominee' => 'America/Menominee', 'America/Merida' => 'America/Merida', 'America/Metlakatla' => 'America/Metlakatla', 'America/Mexico_City' => 'America/Mexico_City', 'America/Miquelon' => 'America/Miquelon', 'America/Moncton' => 'America/Moncton', 'America/Monterrey' => 'America/Monterrey', 'America/Montevideo' => 'America/Montevideo', 'America/Montreal' => 'America/Montreal', 'America/Montserrat' => 'America/Montserrat', 'America/Nassau' => 'America/Nassau', 'America/New_York' => 'America/New_York', 'America/Nipigon' => 'America/Nipigon', 'America/Nome' => 'America/Nome', 'America/Noronha' => 'America/Noronha', 'America/North_Dakota/Beulah' => 'America/North_Dakota/Beulah', 'America/North_Dakota/Center' => 'America/North_Dakota/Center', 'America/North_Dakota/New_Salem' => 'America/North_Dakota/New_Salem', 'America/Nuuk' => 'America/Nuuk', 'America/Ojinaga' => 'America/Ojinaga', 'America/Panama' => 'America/Panama', 'America/Pangnirtung' => 'America/Pangnirtung', 'America/Paramaribo' => 'America/Paramaribo', 'America/Phoenix' => 'America/Phoenix', 'America/Port-au-Prince' => 'America/Port-au-Prince', 'America/Port_of_Spain' => 'America/Port_of_Spain', 'America/Porto_Acre' => 'America/Porto_Acre', 'America/Porto_Velho' => 'America/Porto_Velho', 'America/Puerto_Rico' => 'America/Puerto_Rico', 'America/Punta_Arenas' => 'America/Punta_Arenas', 'America/Rainy_River' => 'America/Rainy_River', 'America/Rankin_Inlet' => 'America/Rankin_Inlet', 'America/Recife' => 'America/Recife', 'America/Regina' => 'America/Regina', 'America/Resolute' => 'America/Resolute', 'America/Rio_Branco' => 'America/Rio_Branco', 'America/Rosario' => 'America/Rosario', 'America/Santa_Isabel' => 'America/Santa_Isabel', 'America/Santarem' => 'America/Santarem', 'America/Santiago' => 'America/Santiago', 'America/Santo_Domingo' => 'America/Santo_Domingo', 'America/Sao_Paulo' => 'America/Sao_Paulo', 'America/Scoresbysund' => 'America/Scoresbysund', 'America/Shiprock' => 'America/Shiprock', 'America/Sitka' => 'America/Sitka', 'America/St_Barthelemy' => 'America/St_Barthelemy', 'America/St_Johns' => 'America/St_Johns', 'America/St_Kitts' => 'America/St_Kitts', 'America/St_Lucia' => 'America/St_Lucia', 'America/St_Thomas' => 'America/St_Thomas', 'America/St_Vincent' => 'America/St_Vincent', 'America/Swift_Current' => 'America/Swift_Current', 'America/Tegucigalpa' => 'America/Tegucigalpa', 'America/Thule' => 'America/Thule', 'America/Thunder_Bay' => 'America/Thunder_Bay', 'America/Tijuana' => 'America/Tijuana', 'America/Toronto' => 'America/Toronto', 'America/Tortola' => 'America/Tortola', 'America/Vancouver' => 'America/Vancouver', 'America/Virgin' => 'America/Virgin', 'America/Whitehorse' => 'America/Whitehorse', 'America/Winnipeg' => 'America/Winnipeg', 'America/Yakutat' => 'America/Yakutat', 'America/Yellowknife' => 'America/Yellowknife', 'Antarctica/Casey' => 'Antarctica/Casey', 'Antarctica/Davis' => 'Antarctica/Davis', 'Antarctica/DumontDUrville' => 'Antarctica/DumontDUrville', 'Antarctica/Macquarie' => 'Antarctica/Macquarie', 'Antarctica/Mawson' => 'Antarctica/Mawson', 'Antarctica/McMurdo' => 'Antarctica/McMurdo', 'Antarctica/Palmer' => 'Antarctica/Palmer', 'Antarctica/Rothera' => 'Antarctica/Rothera', 'Antarctica/South_Pole' => 'Antarctica/South_Pole', 'Antarctica/Syowa' => 'Antarctica/Syowa', 'Antarctica/Troll' => 'Antarctica/Troll', 'Antarctica/Vostok' => 'Antarctica/Vostok', 'Arctic/Longyearbyen' => 'Arctic/Longyearbyen', 'Asia/Aden' => 'Asia/Aden', 'Asia/Almaty' => 'Asia/Almaty', 'Asia/Amman' => 'Asia/Amman', 'Asia/Anadyr' => 'Asia/Anadyr', 'Asia/Aqtau' => 'Asia/Aqtau', 'Asia/Aqtobe' => 'Asia/Aqtobe', 'Asia/Ashgabat' => 'Asia/Ashgabat', 'Asia/Ashkhabad' => 'Asia/Ashkhabad', 'Asia/Atyrau' => 'Asia/Atyrau', 'Asia/Baghdad' => 'Asia/Baghdad', 'Asia/Bahrain' => 'Asia/Bahrain', 'Asia/Baku' => 'Asia/Baku', 'Asia/Bangkok' => 'Asia/Bangkok', 'Asia/Barnaul' => 'Asia/Barnaul', 'Asia/Beirut' => 'Asia/Beirut', 'Asia/Bishkek' => 'Asia/Bishkek', 'Asia/Brunei' => 'Asia/Brunei', 'Asia/Calcutta' => 'Asia/Calcutta', 'Asia/Chita' => 'Asia/Chita', 'Asia/Choibalsan' => 'Asia/Choibalsan', 'Asia/Chongqing' => 'Asia/Chongqing', 'Asia/Chungking' => 'Asia/Chungking', 'Asia/Colombo' => 'Asia/Colombo', 'Asia/Dacca' => 'Asia/Dacca', 'Asia/Damascus' => 'Asia/Damascus', 'Asia/Dhaka' => 'Asia/Dhaka', 'Asia/Dili' => 'Asia/Dili', 'Asia/Dubai' => 'Asia/Dubai', 'Asia/Dushanbe' => 'Asia/Dushanbe', 'Asia/Famagusta' => 'Asia/Famagusta', 'Asia/Gaza' => 'Asia/Gaza', 'Asia/Harbin' => 'Asia/Harbin', 'Asia/Hebron' => 'Asia/Hebron', 'Asia/Ho_Chi_Minh' => 'Asia/Ho_Chi_Minh', 'Asia/Hong_Kong' => 'Asia/Hong_Kong', 'Asia/Hovd' => 'Asia/Hovd', 'Asia/Irkutsk' => 'Asia/Irkutsk', 'Asia/Istanbul' => 'Asia/Istanbul', 'Asia/Jakarta' => 'Asia/Jakarta', 'Asia/Jayapura' => 'Asia/Jayapura', 'Asia/Jerusalem' => 'Asia/Jerusalem', 'Asia/Kabul' => 'Asia/Kabul', 'Asia/Kamchatka' => 'Asia/Kamchatka', 'Asia/Karachi' => 'Asia/Karachi', 'Asia/Kashgar' => 'Asia/Kashgar', 'Asia/Kathmandu' => 'Asia/Kathmandu', 'Asia/Katmandu' => 'Asia/Katmandu', 'Asia/Khandyga' => 'Asia/Khandyga', 'Asia/Kolkata' => 'Asia/Kolkata', 'Asia/Krasnoyarsk' => 'Asia/Krasnoyarsk', 'Asia/Kuala_Lumpur' => 'Asia/Kuala_Lumpur', 'Asia/Kuching' => 'Asia/Kuching', 'Asia/Kuwait' => 'Asia/Kuwait', 'Asia/Macao' => 'Asia/Macao', 'Asia/Macau' => 'Asia/Macau', 'Asia/Magadan' => 'Asia/Magadan', 'Asia/Makassar' => 'Asia/Makassar', 'Asia/Manila' => 'Asia/Manila', 'Asia/Muscat' => 'Asia/Muscat', 'Asia/Nicosia' => 'Asia/Nicosia', 'Asia/Novokuznetsk' => 'Asia/Novokuznetsk', 'Asia/Novosibirsk' => 'Asia/Novosibirsk', 'Asia/Omsk' => 'Asia/Omsk', 'Asia/Oral' => 'Asia/Oral', 'Asia/Phnom_Penh' => 'Asia/Phnom_Penh', 'Asia/Pontianak' => 'Asia/Pontianak', 'Asia/Pyongyang' => 'Asia/Pyongyang', 'Asia/Qatar' => 'Asia/Qatar', 'Asia/Qostanay' => 'Asia/Qostanay', 'Asia/Qyzylorda' => 'Asia/Qyzylorda', 'Asia/Rangoon' => 'Asia/Rangoon', 'Asia/Riyadh' => 'Asia/Riyadh', 'Asia/Saigon' => 'Asia/Saigon', 'Asia/Sakhalin' => 'Asia/Sakhalin', 'Asia/Samarkand' => 'Asia/Samarkand', 'Asia/Seoul' => 'Asia/Seoul', 'Asia/Shanghai' => 'Asia/Shanghai', 'Asia/Singapore' => 'Asia/Singapore', 'Asia/Srednekolymsk' => 'Asia/Srednekolymsk', 'Asia/Taipei' => 'Asia/Taipei', 'Asia/Tashkent' => 'Asia/Tashkent', 'Asia/Tbilisi' => 'Asia/Tbilisi', 'Asia/Tehran' => 'Asia/Tehran', 'Asia/Tel_Aviv' => 'Asia/Tel_Aviv', 'Asia/Thimbu' => 'Asia/Thimbu', 'Asia/Thimphu' => 'Asia/Thimphu', 'Asia/Tokyo' => 'Asia/Tokyo', 'Asia/Tomsk' => 'Asia/Tomsk', 'Asia/Ujung_Pandang' => 'Asia/Ujung_Pandang', 'Asia/Ulaanbaatar' => 'Asia/Ulaanbaatar', 'Asia/Ulan_Bator' => 'Asia/Ulan_Bator', 'Asia/Urumqi' => 'Asia/Urumqi', 'Asia/Ust-Nera' => 'Asia/Ust-Nera', 'Asia/Vientiane' => 'Asia/Vientiane', 'Asia/Vladivostok' => 'Asia/Vladivostok', 'Asia/Yakutsk' => 'Asia/Yakutsk', 'Asia/Yangon' => 'Asia/Yangon', 'Asia/Yekaterinburg' => 'Asia/Yekaterinburg', 'Asia/Yerevan' => 'Asia/Yerevan', 'Atlantic/Azores' => 'Atlantic/Azores', 'Atlantic/Bermuda' => 'Atlantic/Bermuda', 'Atlantic/Canary' => 'Atlantic/Canary', 'Atlantic/Cape_Verde' => 'Atlantic/Cape_Verde', 'Atlantic/Faeroe' => 'Atlantic/Faeroe', 'Atlantic/Faroe' => 'Atlantic/Faroe', 'Atlantic/Jan_Mayen' => 'Atlantic/Jan_Mayen', 'Atlantic/Madeira' => 'Atlantic/Madeira', 'Atlantic/Reykjavik' => 'Atlantic/Reykjavik', 'Atlantic/South_Georgia' => 'Atlantic/South_Georgia', 'Atlantic/St_Helena' => 'Atlantic/St_Helena', 'Atlantic/Stanley' => 'Atlantic/Stanley', 'Australia/ACT' => 'Australia/ACT', 'Australia/Adelaide' => 'Australia/Adelaide', 'Australia/Brisbane' => 'Australia/Brisbane', 'Australia/Broken_Hill' => 'Australia/Broken_Hill', 'Australia/Canberra' => 'Australia/Canberra', 'Australia/Currie' => 'Australia/Currie', 'Australia/Darwin' => 'Australia/Darwin', 'Australia/Eucla' => 'Australia/Eucla', 'Australia/Hobart' => 'Australia/Hobart', 'Australia/LHI' => 'Australia/LHI', 'Australia/Lindeman' => 'Australia/Lindeman', 'Australia/Lord_Howe' => 'Australia/Lord_Howe', 'Australia/Melbourne' => 'Australia/Melbourne', 'Australia/NSW' => 'Australia/NSW', 'Australia/North' => 'Australia/North', 'Australia/Perth' => 'Australia/Perth', 'Australia/Queensland' => 'Australia/Queensland', 'Australia/South' => 'Australia/South', 'Australia/Sydney' => 'Australia/Sydney', 'Australia/Tasmania' => 'Australia/Tasmania', 'Australia/Victoria' => 'Australia/Victoria', 'Australia/West' => 'Australia/West', 'Australia/Yancowinna' => 'Australia/Yancowinna', 'Brazil/Acre' => 'Brazil/Acre', 'Brazil/DeNoronha' => 'Brazil/DeNoronha', 'Brazil/East' => 'Brazil/East', 'Brazil/West' => 'Brazil/West', 'CET' => 'CET', 'CST6CDT' => 'CST6CDT', 'Canada/Atlantic' => 'Canada/Atlantic', 'Canada/Central' => 'Canada/Central', 'Canada/Eastern' => 'Canada/Eastern', 'Canada/Mountain' => 'Canada/Mountain', 'Canada/Newfoundland' => 'Canada/Newfoundland', 'Canada/Pacific' => 'Canada/Pacific', 'Canada/Saskatchewan' => 'Canada/Saskatchewan', 'Canada/Yukon' => 'Canada/Yukon', 'Chile/Continental' => 'Chile/Continental', 'Chile/EasterIsland' => 'Chile/EasterIsland', 'Cuba' => 'Cuba', 'EET' => 'EET', 'EST' => 'EST', 'EST5EDT' => 'EST5EDT', 'Egypt' => 'Egypt', 'Eire' => 'Eire', 'Etc/GMT' => 'Etc/GMT', 'Etc/GMT+0' => 'Etc/GMT+0', 'Etc/GMT+1' => 'Etc/GMT+1', 'Etc/GMT+10' => 'Etc/GMT+10', 'Etc/GMT+11' => 'Etc/GMT+11', 'Etc/GMT+12' => 'Etc/GMT+12', 'Etc/GMT+2' => 'Etc/GMT+2', 'Etc/GMT+3' => 'Etc/GMT+3', 'Etc/GMT+4' => 'Etc/GMT+4', 'Etc/GMT+5' => 'Etc/GMT+5', 'Etc/GMT+6' => 'Etc/GMT+6', 'Etc/GMT+7' => 'Etc/GMT+7', 'Etc/GMT+8' => 'Etc/GMT+8', 'Etc/GMT+9' => 'Etc/GMT+9', 'Etc/GMT-0' => 'Etc/GMT-0', 'Etc/GMT-1' => 'Etc/GMT-1', 'Etc/GMT-10' => 'Etc/GMT-10', 'Etc/GMT-11' => 'Etc/GMT-11', 'Etc/GMT-12' => 'Etc/GMT-12', 'Etc/GMT-13' => 'Etc/GMT-13', 'Etc/GMT-14' => 'Etc/GMT-14', 'Etc/GMT-2' => 'Etc/GMT-2', 'Etc/GMT-3' => 'Etc/GMT-3', 'Etc/GMT-4' => 'Etc/GMT-4', 'Etc/GMT-5' => 'Etc/GMT-5', 'Etc/GMT-6' => 'Etc/GMT-6', 'Etc/GMT-7' => 'Etc/GMT-7', 'Etc/GMT-8' => 'Etc/GMT-8', 'Etc/GMT-9' => 'Etc/GMT-9', 'Etc/GMT0' => 'Etc/GMT0', 'Etc/Greenwich' => 'Etc/Greenwich', 'Etc/UCT' => 'Etc/UCT', 'Etc/UTC' => 'Etc/UTC', 'Etc/Universal' => 'Etc/Universal', 'Etc/Zulu' => 'Etc/Zulu', 'Europe/Amsterdam' => 'Europe/Amsterdam', 'Europe/Andorra' => 'Europe/Andorra', 'Europe/Astrakhan' => 'Europe/Astrakhan', 'Europe/Athens' => 'Europe/Athens', 'Europe/Belfast' => 'Europe/Belfast', 'Europe/Belgrade' => 'Europe/Belgrade', 'Europe/Berlin' => 'Europe/Berlin', 'Europe/Bratislava' => 'Europe/Bratislava', 'Europe/Brussels' => 'Europe/Brussels', 'Europe/Bucharest' => 'Europe/Bucharest', 'Europe/Budapest' => 'Europe/Budapest', 'Europe/Busingen' => 'Europe/Busingen', 'Europe/Chisinau' => 'Europe/Chisinau', 'Europe/Copenhagen' => 'Europe/Copenhagen', 'Europe/Dublin' => 'Europe/Dublin', 'Europe/Gibraltar' => 'Europe/Gibraltar', 'Europe/Guernsey' => 'Europe/Guernsey', 'Europe/Helsinki' => 'Europe/Helsinki', 'Europe/Isle_of_Man' => 'Europe/Isle_of_Man', 'Europe/Istanbul' => 'Europe/Istanbul', 'Europe/Jersey' => 'Europe/Jersey', 'Europe/Kaliningrad' => 'Europe/Kaliningrad', 'Europe/Kiev' => 'Europe/Kiev', 'Europe/Kirov' => 'Europe/Kirov', 'Europe/Lisbon' => 'Europe/Lisbon', 'Europe/Ljubljana' => 'Europe/Ljubljana', 'Europe/London' => 'Europe/London', 'Europe/Luxembourg' => 'Europe/Luxembourg', 'Europe/Madrid' => 'Europe/Madrid', 'Europe/Malta' => 'Europe/Malta', 'Europe/Mariehamn' => 'Europe/Mariehamn', 'Europe/Minsk' => 'Europe/Minsk', 'Europe/Monaco' => 'Europe/Monaco', 'Europe/Moscow' => 'Europe/Moscow', 'Europe/Nicosia' => 'Europe/Nicosia', 'Europe/Oslo' => 'Europe/Oslo', 'Europe/Paris' => 'Europe/Paris', 'Europe/Podgorica' => 'Europe/Podgorica', 'Europe/Prague' => 'Europe/Prague', 'Europe/Riga' => 'Europe/Riga', 'Europe/Rome' => 'Europe/Rome', 'Europe/Samara' => 'Europe/Samara', 'Europe/San_Marino' => 'Europe/San_Marino', 'Europe/Sarajevo' => 'Europe/Sarajevo', 'Europe/Saratov' => 'Europe/Saratov', 'Europe/Simferopol' => 'Europe/Simferopol', 'Europe/Skopje' => 'Europe/Skopje', 'Europe/Sofia' => 'Europe/Sofia', 'Europe/Stockholm' => 'Europe/Stockholm', 'Europe/Tallinn' => 'Europe/Tallinn', 'Europe/Tirane' => 'Europe/Tirane', 'Europe/Tiraspol' => 'Europe/Tiraspol', 'Europe/Ulyanovsk' => 'Europe/Ulyanovsk', 'Europe/Uzhgorod' => 'Europe/Uzhgorod', 'Europe/Vaduz' => 'Europe/Vaduz', 'Europe/Vatican' => 'Europe/Vatican', 'Europe/Vienna' => 'Europe/Vienna', 'Europe/Vilnius' => 'Europe/Vilnius', 'Europe/Volgograd' => 'Europe/Volgograd', 'Europe/Warsaw' => 'Europe/Warsaw', 'Europe/Zagreb' => 'Europe/Zagreb', 'Europe/Zaporozhye' => 'Europe/Zaporozhye', 'Europe/Zurich' => 'Europe/Zurich', 'Factory' => 'Factory', 'GB' => 'GB', 'GB-Eire' => 'GB-Eire', 'GMT' => 'GMT', 'GMT+0' => 'GMT+0', 'GMT-0' => 'GMT-0', 'GMT0' => 'GMT0', 'Greenwich' => 'Greenwich', 'HST' => 'HST', 'Hongkong' => 'Hongkong', 'Iceland' => 'Iceland', 'Indian/Antananarivo' => 'Indian/Antananarivo', 'Indian/Chagos' => 'Indian/Chagos', 'Indian/Christmas' => 'Indian/Christmas', 'Indian/Cocos' => 'Indian/Cocos', 'Indian/Comoro' => 'Indian/Comoro', 'Indian/Kerguelen' => 'Indian/Kerguelen', 'Indian/Mahe' => 'Indian/Mahe', 'Indian/Maldives' => 'Indian/Maldives', 'Indian/Mauritius' => 'Indian/Mauritius', 'Indian/Mayotte' => 'Indian/Mayotte', 'Indian/Reunion' => 'Indian/Reunion', 'Iran' => 'Iran', 'Israel' => 'Israel', 'Jamaica' => 'Jamaica', 'Japan' => 'Japan', 'Kwajalein' => 'Kwajalein', 'Libya' => 'Libya', 'MET' => 'MET', 'MST' => 'MST', 'MST7MDT' => 'MST7MDT', 'Mexico/BajaNorte' => 'Mexico/BajaNorte', 'Mexico/BajaSur' => 'Mexico/BajaSur', 'Mexico/General' => 'Mexico/General', 'NZ' => 'NZ', 'NZ-CHAT' => 'NZ-CHAT', 'Navajo' => 'Navajo', 'PRC' => 'PRC', 'PST8PDT' => 'PST8PDT', 'Pacific/Apia' => 'Pacific/Apia', 'Pacific/Auckland' => 'Pacific/Auckland', 'Pacific/Bougainville' => 'Pacific/Bougainville', 'Pacific/Chatham' => 'Pacific/Chatham', 'Pacific/Chuuk' => 'Pacific/Chuuk', 'Pacific/Easter' => 'Pacific/Easter', 'Pacific/Efate' => 'Pacific/Efate', 'Pacific/Enderbury' => 'Pacific/Enderbury', 'Pacific/Fakaofo' => 'Pacific/Fakaofo', 'Pacific/Fiji' => 'Pacific/Fiji', 'Pacific/Funafuti' => 'Pacific/Funafuti', 'Pacific/Galapagos' => 'Pacific/Galapagos', 'Pacific/Gambier' => 'Pacific/Gambier', 'Pacific/Guadalcanal' => 'Pacific/Guadalcanal', 'Pacific/Guam' => 'Pacific/Guam', 'Pacific/Honolulu' => 'Pacific/Honolulu', 'Pacific/Johnston' => 'Pacific/Johnston', 'Pacific/Kiritimati' => 'Pacific/Kiritimati', 'Pacific/Kosrae' => 'Pacific/Kosrae', 'Pacific/Kwajalein' => 'Pacific/Kwajalein', 'Pacific/Majuro' => 'Pacific/Majuro', 'Pacific/Marquesas' => 'Pacific/Marquesas', 'Pacific/Midway' => 'Pacific/Midway', 'Pacific/Nauru' => 'Pacific/Nauru', 'Pacific/Niue' => 'Pacific/Niue', 'Pacific/Norfolk' => 'Pacific/Norfolk', 'Pacific/Noumea' => 'Pacific/Noumea', 'Pacific/Pago_Pago' => 'Pacific/Pago_Pago', 'Pacific/Palau' => 'Pacific/Palau', 'Pacific/Pitcairn' => 'Pacific/Pitcairn', 'Pacific/Pohnpei' => 'Pacific/Pohnpei', 'Pacific/Ponape' => 'Pacific/Ponape', 'Pacific/Port_Moresby' => 'Pacific/Port_Moresby', 'Pacific/Rarotonga' => 'Pacific/Rarotonga', 'Pacific/Saipan' => 'Pacific/Saipan', 'Pacific/Samoa' => 'Pacific/Samoa', 'Pacific/Tahiti' => 'Pacific/Tahiti', 'Pacific/Tarawa' => 'Pacific/Tarawa', 'Pacific/Tongatapu' => 'Pacific/Tongatapu', 'Pacific/Truk' => 'Pacific/Truk', 'Pacific/Wake' => 'Pacific/Wake', 'Pacific/Wallis' => 'Pacific/Wallis', 'Pacific/Yap' => 'Pacific/Yap', 'Poland' => 'Poland', 'Portugal' => 'Portugal', 'ROC' => 'ROC', 'ROK' => 'ROK', 'Singapore' => 'Singapore', 'Turkey' => 'Turkey', 'UCT' => 'UCT', 'US/Alaska' => 'US/Alaska', 'US/Aleutian' => 'US/Aleutian', 'US/Arizona' => 'US/Arizona', 'US/Central' => 'US/Central', 'US/East-Indiana' => 'US/East-Indiana', 'US/Eastern' => 'US/Eastern', 'US/Hawaii' => 'US/Hawaii', 'US/Indiana-Starke' => 'US/Indiana-Starke', 'US/Michigan' => 'US/Michigan', 'US/Mountain' => 'US/Mountain', 'US/Pacific' => 'US/Pacific', 'US/Pacific-New' => 'US/Pacific-New', 'US/Samoa' => 'US/Samoa', 'UTC' => 'UTC', 'Universal' => 'Universal', 'W-SU' => 'W-SU', 'WET' => 'WET', 'Zulu' => 'Zulu']);
    }

    $field_id = "input$config->name";

    if (@$config->glue != 'previous') {
        $html .= '<div class="form-group row align-items-center">';
    }

    if (!empty($config->display_name)) {
        $html .= '<label for="' . he($field_id) . '" class="col-sm-' . ($fixed_width_label ? '2' : 'auto') .' col-form-label">' . he($config->display_name) . "</label>";
    }

    $html .= '<div class="col-auto">';

    if (!empty($config->prefix)) {
        $html .= he("$config->prefix ") . '</div><div class="col-auto">';
    }

    if ($current_value === NULL) {
        if (isset($config->current_value)) {
            $current_value = $config->current_value;
        } else {
            $current_value = Config::get($config->name . '_raw') ? Config::get($config->name . '_raw') : Config::get($config->name);
        }
    }

    if ($config->type == 'string') {
        $html .= '<input class="form-control" type="text" id="' . he($field_id) . '" name="' . he($config->name) . '" value="' . he($current_value) . '" onchange="config_value_changed(this)" style="min-width: 300px;" />';
    }
    elseif ($config->type == 'multi-string') {
        $html .= '<textarea class="form-control" id="' . he($field_id) . '" name="' . he($config->name) . '"onchange="config_value_changed(this)" style="width: 300px; height: 150px">';
        $html .= implode("\n", $current_value);
        $html .= '</textarea>';
    }
    elseif ($config->type == 'integer') {
        $html .= '<input class="form-control" type="number" step="1" id="' . he($field_id) . '" name="' . he($config->name) . '" value="' . he($current_value) . '" onchange="config_value_changed(this)" />';
    }
    elseif ($config->type == 'select' || $config->type == 'toggles') {
        if (!array_contains(array_keys($config->possible_values), $current_value)) {
            $config->possible_values = array_merge([$current_value => $current_value], $config->possible_values);
        }
        if ($config->type == 'toggles') {
            $html .= '<div class="btn-group btn-group-toggle" data-toggle="buttons">';
            foreach ($config->possible_values as $v => $d) {
                $selected = $v == $current_value;
                $html .= '<label class="btn btn-outline-primary ' . ($selected ? 'active' : '') . '">';
                $html .= '<input type="radio" name="' . he($config->name) . '" id="' . he($field_id) . '" value="' . he($v) . '" autocomplete="off" onchange="config_value_changed(this)" ' . ($selected ? 'checked' : '') . '>' . he($d);
                $html .= '</label>';
            }
            $html .= '</div>';
        } else {
            $html .= '<select class="form-control" id="' . he($field_id) . '" name="' . he($config->name) . '" onchange="config_value_changed(this)">';
            foreach ($config->possible_values as $v => $d) {
                $selected = '';
                if ($v == $current_value) {
                    $selected = "selected";
                }
                $html .= '<option value="' . he($v) . '" ' . $selected . '>' . he($d) . '</option>';
            }
            $html .= '</select>';
        }
    }
    elseif ($config->type == 'sp_drives') {
        $html .= '<select class="form-control" id="' . he($field_id) . '" name="' . he($config->name) . '" onchange="config_value_changed(this)" multiple>';
        $config->possible_values = Config::storagePoolDrives();
        foreach ($config->possible_values as $v) {
            $selected = '';
            if (array_contains($current_value, $v)) {
                $selected = "selected";
            }
            $html .= '<option value="' . he($v) . '" ' . $selected . '>' . he($v) . '</option>';
        }
        $html .= '</select>';
    }
    elseif ($config->type == 'bool') {
        $html .= '<div class="btn-group btn-group-toggle" data-toggle="buttons">';
        $html .= '<label class="btn btn-outline-primary ' . ($current_value ? 'active' : '') . '">';
        $html .= '<input type="radio" name="' . he($config->name) . '" id="' . he($field_id) . '" value="yes" autocomplete="off" onchange="config_value_changed(this)" ' . ($current_value ? 'checked' : '') . '>Yes';
        $html .= '</label>';
        $html .= '<label class="btn btn-outline-primary ' . (!$current_value ? 'active' : '') . '">';
        $html .= '<input type="radio" name="' . he($config->name) . '" id="' . he($field_id) . '" value="no" autocomplete="off" onchange="config_value_changed(this)" ' . (!$current_value ? 'checked' : '') . '>No';
        $html .= '</label>';
        $html .= '</div>';
    }
    elseif ($config->type == 'bytes' || $config->type == 'kbytes') {
        if ($config->type == 'kbytes') {
            $current_value *= 1024;
        }
        $current_value = bytes_to_human($current_value, FALSE);
        $numeric_value = (float) $current_value;
        $html .= '<input class="form-control" type="number" step="1" min="0" id="' . he($field_id) . '" name="' . he($config->name) . '" onchange="config_value_changed(this)" value="' . he($numeric_value) .'" style="max-width: 90px">';
        $html .= '</div>';
        $html .= '<div class="col-auto">';
        $html .= '<select class="form-control" name="' . he($config->name) . '_suffix" onchange="config_value_changed(this)">';
        foreach (['gb' => 'GiB', 'mb' => 'MiB', 'kb' => 'KiB'] as $v => $d) {
            $selected = '';
            if (string_ends_with($current_value, $v)) {
                $selected = "selected";
            }
            if (@$config->shorthand) {
                $v = strtoupper($v[0]);
            }
            $html .= '<option value="' . he($v) . '" ' . $selected . '>' . he($d) . '</option>';
        }
        $html .= '</select>';

    }

    if (!empty($config->suffix)) {
        $html .=  '</div><div class="col-auto">' . he(" $config->suffix");
    }
    $html .= '</div>';
    if (@$config->glue != 'next') {
        $html .= '</div>';
    }

    return $html . ' ';
}
?>

<ul class="nav nav-tabs" id="myTab" role="tablist">
    <?php foreach ($configs as $i => $config) : ?>
        <li class="nav-item">
            <a class="nav-link <?php echo $i == 0 ? 'active' : '' ?>" id="id<?php echo md5($config->name) ?>-tab" data-toggle="tab" href="#id<?php echo md5($config->name) ?>" role="tab" aria-controls="id<?php echo md5($config->name) ?>" aria-selected="true"><?php phe($config->name) ?></a>
        </li>
    <?php endforeach; ?>
</ul>
<div class="tab-content" id="myTabContent">
    <?php foreach ($configs as $i => $config) : ?>
        <div class="tab-pane fade show <?php echo $i == 0 ? 'active' : '' ?>" id="id<?php echo md5($config->name) ?>" role="tabpanel" aria-labelledby="id<?php echo md5($config->name) ?>-tab">
            <?php echo get_config_html($config) ?>
        </div>
    <?php endforeach; ?>
</div>
<div id="footer-padding" style="height: 150px">&nbsp;</div>

<script>
    function config_value_changed(el) {
        let name = $(el).attr('name');
        let new_value = $(el).val();
        console.log(name + " = " + new_value);
    }
</script>

</div>

<script src="https://cdn.jsdelivr.net/npm/jquery@3.5.1/dist/jquery.min.js" integrity="sha256-9/aliU8dGd2tb6OSsuzixeV4y/faTqgFtohetphbbj0=" crossorigin="anonymous"></script>
<script src="https://cdn.jsdelivr.net/npm/popper.js@1.16.1/dist/umd/popper.min.js" integrity="sha384-9/reFTGAW83EW2RDu2S0VKaIzap3H66lZH81PoYlFhbGU+6BZp6G7niu735Sk7lN" crossorigin="anonymous"></script>
<script src="https://stackpath.bootstrapcdn.com/bootstrap/4.5.2/js/bootstrap.min.js" integrity="sha384-B4gt1jrGC7Jh4AgTPSdUtOBvfO8shuf57BaghqFfPlYxofvL8/KUEfYiJOMMV+rV" crossorigin="anonymous"></script>
</body>
</html>
