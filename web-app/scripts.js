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

function defer(method) {
    if (window.jQuery) {
        method();
    } else {
        setTimeout(function() { defer(method) }, 50);
    }
}

defer(function() {
    $(function () {
        $('[data-toggle="tooltip"]').tooltip();
    });

    resizeSPDrivesUsageGraphs();
    $(window).resize(function() {
        resizeSPDrivesUsageGraphs();
    });
});

function resizeSPDrivesUsageGraphs() {
    var $table = $('#table-sp-drives');
    let total_width = $table.closest('.col').width() - 30;
    let width_left = total_width - $table.find('td:nth-child(1)').width() - $table.find('td:nth-child(2)').width() - $table.find('td:nth-child(3)').width() - 3*12;
    $('.sp-bar').each(function(i, el) {
        width = $(el).data('width') * width_left;
        $(el).css('width', width + 'px');
    });
}

function toggleSambaShareGreyholeEnabled(el) {
    let $el = $(el);
    let name = $el.attr('name');
    let value = $el.val();
    let share_name = $el.data('sharename')

    let num_copies_field_name = name.replace('gh_enabled', 'num_copies');
    let $num_copies_field = $('[name="' + num_copies_field_name + '"]');

    let vfs_objects_field_name = name.replace('gh_enabled', 'vfs_objects');
    let $vfs_objects_field_name = $('[name="' + vfs_objects_field_name + '"]');
    let vfs_objects = $vfs_objects_field_name.val();

    let dfree_command;
    if (value === 'yes') {
        $num_copies_field.val('1');
        if (vfs_objects.indexOf('greyhole') < 0) {
            vfs_objects = (vfs_objects + " greyhole").trim();
            $vfs_objects_field_name.val(vfs_objects);
        }
        dfree_command = '/usr/bin/greyhole-dfree';
    } else {
        $num_copies_field.val('0');
        vfs_objects = vfs_objects.replace('greyhole', '').replace('  ', ' ').trim();
        $vfs_objects_field_name.val(vfs_objects);
        if (vfs_objects === '') {
            vfs_objects = '___REMOVE___';
        }
        dfree_command = '___REMOVE___';
    }

    ajax_value_changed($el, 'smb.conf:[' + share_name + ']dfree_command', dfree_command, function () {
        ajax_value_changed(null, 'smb.conf:[' + share_name + ']vfs_objects', vfs_objects, function () {
            config_value_changed($num_copies_field);
        });
    });
}

function config_value_changed(el, success) {
    let $el = $(el);
    let name = $el.attr('name');
    let new_value = $el.val();

    if ($('[name="' + name + '_suffix"]').length > 0) {
        let $el2 = $('[name="' + name + '_suffix"]');
        new_value = new_value + $el2.val();
    } else if (name.indexOf('_suffix') > -1) {
        name = name.substr(0, name.length-7);
        let $el2 = $('[name="' + name + '"]');
        new_value = $el2.val() + new_value;
    }

    if (name.indexOf('drive_selection_algorithm') === 0) {
        name = 'drive_selection_algorithm';
        new_value = get_forced_groups_config();
    }

    ajax_value_changed($el, name, new_value, success);
}

function ajax_value_changed($el, name, value, success) {
    console.log(name + " = " + value);
    $.ajax({
        type: 'POST',
        url: './?ajax=config',
        data: 'name=' + encodeURIComponent(name) + '&value=' + encodeURIComponent(value),
        success: function(data, textStatus, jqXHR) {
            if (data.result === 'success') {
                if ($el) {
                    $el.attr('data-toggle', 'tooltip').attr('data-placement', 'bottom').attr('title', 'New value saved').tooltip({trigger: 'manual'}).tooltip('show');
                    setTimeout(function() { $el.tooltip('hide'); }, 2*1000);
                }

                if (data.config_hash === last_known_config_hash) {
                    $('#needs-daemon-restart').hide();
                } else {
                    $('#needs-daemon-restart').show();
                }
                if (data.config_hash_samba === last_known_config_hash_samba) {
                    $('#needs-samba-restart').hide();
                } else {
                    $('#needs-samba-restart').show();
                }
                if (typeof success !== 'undefined') {
                    success();
                }
            } else {
                if (data.result === 'error') {
                    alert(data.message);
                } else {
                    alert("An error occurred. Check your logs for details.");
                }
            }
        },
    });
}

function restartDaemon(button) {
    let $button = $(button);
    $button.text('Restarting...').prop('disabled', true);
    $.ajax({
        type: 'POST',
        url: './?ajax=daemon',
        data: 'action=restart',
        success: function(data, textStatus, jqXHR) {
            if (data.result === 'success') {
                last_known_config_hash = data.config_hash;
                $button.text('Restarted').toggleClass('btn-primary').toggleClass('btn-success');
                setTimeout(function() {
                    $('#needs-daemon-restart').hide();
                    $button.text('Restart').prop('disabled', false).toggleClass('btn-primary').toggleClass('btn-success');
                }, 3*1000);
            } else {
                if (data.result === 'error') {
                    alert(data.message);
                } else {
                    alert("An error occurred. Check your logs for details.");
                }
                $button.text('Restart').prop('disabled', false);
            }
        },
    });
}

function restartSamba(button) {
    let $button = $(button);
    $button.text('Restarting...').prop('disabled', true);
    $.ajax({
        type: 'POST',
        url: './?ajax=samba',
        data: 'action=restart',
        success: function(data, textStatus, jqXHR) {
            if (data.result === 'success') {
                last_known_config_hash_samba = data.config_hash_samba;
                $button.text('Restarted').toggleClass('btn-primary').toggleClass('btn-success');
                setTimeout(function() {
                    $('#needs-samba-restart').hide();
                    $button.text('Restart').prop('disabled', false).toggleClass('btn-primary').toggleClass('btn-success');
                }, 3*1000);
            } else {
                if (data.result === 'error') {
                    alert(data.message);
                } else {
                    alert("An error occurred. Check your logs for details.");
                }
                $button.text('Restart').prop('disabled', false);
            }
        },
    });
}

function get_forced_groups_config() {
    if ($('[name="drive_selection_algorithm_forced"]:checked').val() === 'no') {
        $('.forced_toggleable').closest('.form-group').hide();
        return $('[name="drive_selection_algorithm"]:checked').val();
    }
    $('.forced_toggleable').closest('.form-group').show();
    let groups = [];
    for (let i=0; i<100; i++) {
        let num = $('[name="drive_selection_algorithm_forced['+i+'][num]"]').val();
        let group = $('[name="drive_selection_algorithm_forced['+i+'][group]"]').val();
        if (typeof num !== 'undefined' && num !== '' && typeof group !== 'undefined' && group !== '') {
            if (num !== 'all') {
                num += 'x';
            } else {
                num += ' ';
            }
            groups.push(num + group);
        }
    }

    return 'forced (' + groups.join(', ') + ') ' + $('[name="drive_selection_algorithm"]:checked').val();
}
defer(function(){ get_forced_groups_config(); });

function bytes_to_human(bytes) {
    let units = 'B';
    if (Math.abs(bytes) > 1024) {
        bytes /= 1024;
        units = 'KiB';
    }
    if (Math.abs(bytes) > 1024) {
        bytes /= 1024;
        units = 'MiB';
    }
    if (Math.abs(bytes) > 1024) {
        bytes /= 1024;
        units = 'GiB';
    }
    if (Math.abs(bytes) > 1024) {
        bytes /= 1024;
        units = 'TiB';
    }
    let decimals = (Math.abs(bytes) > 100 ? 0 : (Math.abs(bytes) > 10 ? 1 : 2));
    return parseFloat(bytes).toFixed(decimals) + ' ' + units;
}

function drawPieChartStorage(ctx, stats) {
    let dataset_used = [];
    let dataset_trash = [];
    let dataset_free = [];
    let drives = [];
    for (let sp_drive in stats) {
        let stat = stats[sp_drive];
        if (sp_drive === 'Total') {
            continue;
        }
        drives.push(sp_drive);
        dataset_used.push(stat.used_space - stat.trash_size);
        dataset_trash.push(stat.trash_size);
        dataset_free.push(stat.free_space);
    }
    let dataset_all_drives = dataset_used.concat(dataset_trash).concat(dataset_free);
    let labels_all_drives = [];
    let colors_all_drives = [];
    for (let i in dataset_used) {
        let v = dataset_used[i];
        labels_all_drives.push(drives[i] + " Used: " + bytes_to_human(v * 1024));
        colors_all_drives.push(window.chartColorsSemi.red);
    }
    for (let i in dataset_trash) {
        let v = dataset_trash[i];
        labels_all_drives.push(drives[i] + " Trash: " + bytes_to_human(v * 1024));
        colors_all_drives.push(window.chartColorsSemi.yellow);
    }
    for (let i in dataset_free) {
        let v = dataset_free[i];
        labels_all_drives.push(drives[i] + " Free: " + bytes_to_human(v * 1024));
        colors_all_drives.push(window.chartColorsSemi.green);
    }

    let stat = stats['Total'];
    let total = stat.used_space + stat.trash_size + stat.free_space;
    let labels_summary = [
        'Used: ' + bytes_to_human(stat.used_space * 1024),
        'Trash: ' + bytes_to_human(stat.trash_size * 1024),
        'Free: ' + bytes_to_human(stat.free_space * 1024)
    ];
    new Chart(ctx, {
        type: 'pie',
        data: {
            datasets: [
                {
                    // "Sum" dataset needs to appear first, for Leged to appear correctly
                    weight: 0,
                    data: [stat.used_space, stat.trash_size, stat.free_space],
                    backgroundColor: [
                        window.chartColors.red,
                        window.chartColors.yellow,
                        window.chartColors.green
                    ],
                    labels: labels_summary,
                },
                {
                    weight: 50,
                    data: dataset_all_drives,
                    backgroundColor: colors_all_drives,
                    labels: labels_all_drives
                },
                {
                    weight: 50,
                    data: [stat.used_space, stat.trash_size, stat.free_space],
                    backgroundColor: [
                        window.chartColors.red,
                        window.chartColors.yellow,
                        window.chartColors.green
                    ],
                    labels: labels_summary,
                },
            ],
            labels: labels_summary
        },
        options: {
            cutoutPercentage: 20,
            responsive: true,
            responsiveAnimationDuration: 400,
            legend: {
                position: 'right',
                labels: { fontColor: dark_mode_enabled ? 'white' : '#666' },
            },
            tooltips: {
                callbacks: {
                    label: function (tooltipItem, data) {
                        var label = data.datasets[tooltipItem.datasetIndex].labels[tooltipItem.index] || '';
                        if (label) {
                            label += ' = ';
                        }
                        let value = data.datasets[tooltipItem.datasetIndex].data[tooltipItem.index];
                        var percentage = Math.round(value / total * 100);
                        label += percentage + "%";
                        return label;
                    }
                }
            }
        }
    });
}

function drawPieChartDiskUsage(ctx, du_stats) {
    let dataset = [];
    let labels = [];
    let colors = [];
    let avail_colors = ['#003f5c','#58508d','#bc5090','#ff6361','#ffa600'];
    for (let i in du_stats) {
        let row = du_stats[i];
        dataset.push(parseFloat(row.size));
        labels.push(row.file_path + ": " + bytes_to_human(row.size));
        colors.push(avail_colors[i % avail_colors.length]);
    }

    new Chart(ctx, {
        type: 'pie',
        data: {
            datasets: [
                {
                    data: dataset,
                    backgroundColor: colors,
                },
            ],
            labels: labels
        },
        options: {
            cutoutPercentage: 20,
            responsive: true,
            responsiveAnimationDuration: 400,
            legend: {
                position: 'right',
                labels: { fontColor: dark_mode_enabled ? 'white' : '#666' },
            },
            tooltips: {
                callbacks: {
                    label: function(tooltipItem, data) {
                        return data.labels[tooltipItem.index];
                    }
                }
            }
        }
    });
}

function toggleDarkMode() {
    dark_mode_enabled = !dark_mode_enabled;
    document.cookie = "darkmode=" + (dark_mode_enabled ? '1' : '0') + "; expires=Thu, 1 Sep 2050 12:00:00 UTC";
    location.reload();
}

function addStoragePoolDrive(button) {
    let $modal = $(button).closest('.modal');
    let sp_drive = $modal.find('[name=storage_pool_drive]').val();

    $modal.find('input, select').each(function(index, el) {
        $(el).attr('name', $(el).attr('name').replace('__new__', sp_drive));
    });

    // This will save all values as a single line: "storage_pool_drive = /mnt/hddX/gh, min_free: 10gb"
    config_value_changed($modal.find('select'), function() {
        // Success
        location.reload();
    });
}

function addSambaUser(button) {
    let $button = $(button);
    let $modal = $button.closest('.modal');
    let username = $modal.find('[name=samba_username]').val();
    let password = $modal.find('[name=samba_password]').val();

    let button_original_text = $button.text();
    $button.text('Creating...').prop('disabled', true);
    $.ajax({
        type: 'POST',
        url: './?ajax=samba',
        data: 'action=add_user&username=' + encodeURIComponent(username) + '&password=' + encodeURIComponent(password),
        success: function(data, textStatus, jqXHR) {
            if (data.result === 'success') {
                $button.text('User Created').toggleClass('btn-primary').toggleClass('btn-success');
                setTimeout(function() {
                    $button.text('Reloading page...');
                    location.reload();
                }, 3*1000);
            } else {
                if (data.result === 'error') {
                    alert(data.message);
                } else {
                    alert("An error occurred. Check your logs for details.");
                }
                $button.text(button_original_text).prop('disabled', false);
            }
        },
    });
}
