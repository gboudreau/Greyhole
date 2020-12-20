
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
        $(function () {
            method();
        });
    } else {
        setTimeout(function() { defer(method) }, 50);
    }
}

let urlParams = {};
function parse_url_params() {
    let match,
        pl     = /\+/g,  // Regex for replacing addition symbol with a space
        search = /([^&=]+)=?([^&]*)/g,
        decode = function (s) { return decodeURIComponent(s.replace(pl, " ")); },
        query  = window.location.search.substring(1);
    urlParams = {};
    while (match = search.exec(query)) {
        urlParams[decode(match[1])] = decode(match[2]);
    }
}

let tab_changed_functions = {
    id_l1_status_tab:            function () { loadStatus(); topTabChanged(); },
    id_l2_status_logs_tab:       loadStatusLogs,
    id_l2_status_queue_tab:      loadStatusQueue,
    id_l2_status_past_tasks_tab: loadStatusPastTasks,
    id_l2_status_fsck_tab:       loadStatusFsckReport,
    id_l2_status_balance_tab:    loadStatusBalanceReport,
    id_l1_spool_tab:             loadStoragePool,
    id_l1_trashman_tab:          loadTrashmanContent,
    id_l2_actions_trash_tab:     loadActionsTrashContent,
};
function topTabChanged() {
    let visible_tab_id = $('.navbar-nav .active').attr('aria-controls');
    let $target = $('#' + visible_tab_id).find('.nav-tabs .active');
    if ($target.length > 0) {
        // Will load data from the currently visible sub-tab
        changedTab($target[0]);
    } else {
        changedTab($('.navbar-nav .active')[0]);
    }
}

defer(function() {
    $(function () {
        $('.nav .nav-link').on('shown.bs.tab', function (evt) {
            changedTab(evt.target);
        });

        $(window).on('popstate', function (evt) {
            parse_url_params();
            for (let selected_tab of evt.originalEvent.state.selected_tabs) {
                skip_changed_tab_event = true;
                $('#' + selected_tab).tab('show');
            }
        });

        $(window).resize(function() {
            resizeSPDrivesUsageGraphs();
        });

        $('[data-toggle="tooltip"]').tooltip();

        checkSambaConfig();

        $('#past-tasks-table').DataTable({
            processing: true,
            serverSide: true,
            ajax: './?ajax=get_status_past_tasks',
            columns: [
                { data: 'id', orderSequence: ['desc', 'asc'] },
                { data: 'event_date', orderSequence: ['desc', 'asc'] },
                { data: 'action' },
                { data: 'share' },
                { data: 'full_path' }
            ],
            order: [[0, 'desc']],
            pageLength: 10,
            responsive: true,
            "fnRowCallback": function(nRow, aData, iDisplayIndex, iDisplayIndexFull) {
                $(nRow).addClass(aData.action);
            },
        });

        $('#trashman-table').DataTable({
            processing: true,
            serverSide: true,
            ajax: './?ajax=get_trashman_content&dir=' + encodeURIComponent($('#trashman-current-dir').val()),
            columns: [
                { data: 'path' },
                { data: 'size', orderSequence: ['desc', 'asc'] },
                { data: 'copies', orderSequence: ['desc', 'asc'], width: '110px' },
                { data: 'modified', width: '140px' },
                { data: 'actions', sorting: false }
            ],
            order: [[0, 'asc']],
            paging: false,
            searching: false,
            info: false,
            responsive: true,
            "fnRowCallback": function(nRow, aData, iDisplayIndex, iDisplayIndexFull) {
                $(nRow).data('path', aData.raw_path);
                $(nRow).data('copies', aData.copies);
                $(nRow).data('size', aData.size);
                $(nRow).data('size-delete', aData.size_delete);
                $(nRow).data('copies-restore', aData.copies_restore);
                $(nRow).data('size-restore', aData.size_restore);
            },
        }).on('draw', function () {
            // Adjust columns size
            $('#trashman-table').DataTable().columns.adjust();
        });

        loadStatus();
        topTabChanged();
    });
});

function trashmanGoToParent() {
    let current_dir = $('#trashman-current-dir').val().split('/');
    current_dir.pop();
    let new_dir = current_dir.join('/');
    trashmanDataForDir(new_dir);
}

function trashmanEnterFolder(btn) {
    let folder = $(btn).closest('tr').data('path');
    let current_dir = $('#trashman-current-dir').val();
    let new_dir = current_dir + '/' + folder;
    trashmanDataForDir(new_dir);
}

function trashmanDelete(btn) {
    let $row = $(btn).closest('tr');
    let folder = $('#trashman-current-dir').val() + '/' + $row.data('path');
    folder = folder.substring(2);

    let $modal = $('#modal-trashman-delete');
    $modal.find('.copies').text($row.data('copies'));
    $modal.find('.path').text(folder);
    $modal.find('.size').html($row.data('size-delete'));
    $modal.find('.btn-danger').data('folder', folder);
    $modal.modal('show');
}

function trashmanConfirmDelete(btn) {
    let folder = $(btn).data('folder');
    ajaxCallFromButton(btn, 'delete_from_trash', 'folder=' + encodeURIComponent(folder), 'Deleting...', 'Deleted', 'Delete forever', function (data, $button) {
        $(btn).closest('.modal').modal('hide');
        let dir = $('#trashman-current-dir').val();
        trashmanDataForDir(dir);
    }, 0);
}

function trashmanRestore(btn) {
    let $row = $(btn).closest('tr');
    let folder = $('#trashman-current-dir').val() + '/' + $row.data('path');
    folder = folder.substring(2);

    let $modal = $('#modal-trashman-restore');
    $modal.find('.copies').text($row.data('copies-restore'));
    $modal.find('.path').text(folder);
    $modal.find('.size').html($row.data('size-restore'));
    $modal.find('.btn-success').data('folder', folder);
    $modal.modal('show');
}

function trashmanConfirmRestore(btn) {
    let folder = $(btn).data('folder');
    ajaxCallFromButton(btn, 'restore_from_trash', 'folder=' + encodeURIComponent(folder), 'Restoring...', 'Restored', 'Restore', function (data, $button) {
        $(btn).closest('.modal').modal('hide');
        let dir = $('#trashman-current-dir').val();
        trashmanDataForDir(dir);
    }, 0);
}

function trashmanDataForDir(new_dir) {
    $('#trashman-current-dir').val(new_dir);

    // ajax.url is normally only set when the Datatable is initialized; we need to update it because it needs to use new_dir
    $('#trashman-table').DataTable().ajax.url('./?ajax=get_trashman_content&dir=' + encodeURIComponent(new_dir));
    // Reload data from server
    $('#trashman-table').DataTable().ajax.reload();

    // Show/hide current folder, based of if we're showing the root folder or not
    if (new_dir === '.') {
        $('#trashman-current-dir-header').hide();
    } else {
        $('#trashman-current-dir-header').show();
        $('#trashman-current-dir-header').text(' | ' + new_dir.substring(2));
    }
}

function resizeSPDrivesUsageGraphs() {
    $('.table-sp-drives').each(function(i, el) {
        let $table = $(el);
        let width_left = $table.closest('.col').width() - 30; // 15+15 padding left-right
        let num_rows = $table.find('tr:nth-child(1)').find('th').length;
        for (let i=1; i<num_rows; i++) {
            width_left -= $table.find('td:nth-child('+i+')').width();
            width_left -= 12; // 6+6 padding left-right
        }
        $table.find('.sp-bar').each(function(i, el) {
            let width = $(el).data('width') * width_left;
            $(el).css('width', width + 'px');
            if ($(el).hasClass('treemap')) {
                let $table = $(el).closest('table');
                let color = getTreemapColor($(el).data('value'), $table.data('min-value'), $table.data('max-value'));
                $(el).css('background-color', color);
            }
        });
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
    // console.log(name + " = " + value);
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

function ajaxCallFromButton(button, ajax_action, data, onbusy_btn_text, onsuccess_btn_text, final_btn_text, onsuccess, onsuccess_delay) {
    let $button = $(button);
    let original_btn_text = $button.text();
    $button.text(onbusy_btn_text).prop('disabled', true);
    $.ajax({
        type: 'POST',
        url: './?ajax=' + ajax_action,
        data: data,
        success: function(data, textStatus, jqXHR) {
            if (data.result === 'success') {
                if (onsuccess_delay > 0) {
                    $button.text(onsuccess_btn_text);
                }
                $button.toggleClass('btn-primary').toggleClass('btn-success');
                setTimeout(function() {
                    $button.text(final_btn_text).prop('disabled', false).toggleClass('btn-primary').toggleClass('btn-success');
                    onsuccess(data, $button);
                }, onsuccess_delay*1000);
            } else {
                if (data.result === 'error') {
                    alert(data.message);
                } else {
                    alert("An error occurred. Check your logs for details.");
                }
                $button.text(original_btn_text).prop('disabled', false);
            }
        },
    });
}

function restartDaemon(button) {
    ajaxCallFromButton(button, 'daemon', 'action=restart', 'Restarting...', 'Restarted', 'Restart', function (data, $button) {
        last_known_config_hash = data.config_hash;
        $('#needs-daemon-restart').hide();
    }, 3);
}

function restartSamba(button) {
    ajaxCallFromButton(button, 'samba', 'action=restart', 'Restarting...', 'Restarted', 'Restart', function (data, $button) {
        last_known_config_hash_samba = data.config_hash_samba;
        $('#needs-samba-restart').hide();
    }, 3);
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
            maintainAspectRatio: false,
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
    let paths = [];
    let avail_colors = ['#003f5c','#58508d','#bc5090','#ff6361','#ffa600'];
    for (let i in du_stats) {
        let row = du_stats[i];
        dataset.push(parseFloat(row.size));
        paths.push(row.file_path + ':' + row.depth);
        colors.push(avail_colors[i % avail_colors.length]);
        let file_path = row.file_path;
        labels.push(file_path.split('/').pop() + ": " + bytes_to_human(row.size));
    }

    function selectAtIndex(index) {
        let path = paths[index].split(':');
        let next_level = parseInt(path[1]) + 1;
        path = path[0];
        document.cookie = "back_to_url=" + location.href + "; path=/; expires=Thu, 1 Sep 2050 12:00:00 UTC";
        location.href = './du/?level=' + next_level + '&path=' + encodeURIComponent('/' + path);
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
                onClick: function (e, legendItem) {
                    selectAtIndex(legendItem.index);
                },
            },
            tooltips: {
                callbacks: {
                    label: function(tooltipItem, data) {
                        return data.labels[tooltipItem.index];
                    }
                }
            },
            onClick: function (e, elements) {
                if (elements.length === 0) {
                    return;
                }
                selectAtIndex(elements[0]._index);
            }
        }
    });
}

function getTreemapColor(value, min, max) {
    value = parseInt(value);
    min = parseInt(min);
    max = parseInt(max);

    let largest, smallest;
    if (dark_mode_enabled) {
        largest = [72, 143, 49];
        smallest = [222, 66, 91];
    } else {
        largest = [72, 143, 49];
        smallest = [222, 66, 91];
    }
    let middle = [235, 235, 235];

    let range = max - min;
    if (range === 0) {
        range = value;
    } else {
        value -= min;
    }
    let where = value / range;
    let from, to;
    if (where > 0.5) {
        where = 2 * (where - 0.5);
        from = middle;
        to = largest;
    } else {
        where = 2 * where;
        from = smallest;
        to = middle;
    }
    let r = from[0] + (to[0] - from[0]) * where;
    let g = from[1] + (to[1] - from[1]) * where;
    let b = from[2] + (to[2] - from[2]) * where;
    return 'rgb(' + Math.round(r) + ', ' + Math.round(g) + ',' + Math.round(b) + ')';
}

function drawTreeMapDiskUsage(ctx, du_stats) {
    let dataset = [];
    let paths = [];
    let max = 0, min = null;

    du_stats.sort(function (a, b) {
        return (a.size === b.size ? 0 : ( a.size > b.size ? -1 : 1));
    });

    for (let i in du_stats) {
        let row = du_stats[i];
        row.human_size = bytes_to_human(row.size);
        row.label = row.file_path.split('/').pop();
        row.full_label = row.label + " = " + row.human_size;
        paths.push(row.file_path + ':' + row.depth);
        dataset.push(row);
        if (row.size > max) {
            max = row.size;
        }
        if (min === null || row.size < min) {
            min = row.size;
        }
    }

    function selectAtIndex(index) {
        let path = paths[index].split(':');
        let next_level = parseInt(path[1]) + 1;
        path = path[0];
        if (path === 'Files') {
            return;
        }
        location.href = './?level=' + next_level + '&path=' + encodeURIComponent(path);
    }

    new Chart(ctx, {
        type: 'treemap',
        data: {
            datasets: [
                {
                    tree: dataset,
                    key: 'size',
                    groups: ['full_label'],
                    backgroundColor: function(ctx) {
                        return getTreemapColor(dataset[ctx.dataIndex].size, min, max);
                    },
                    fontColor: 'black',
                    fontFamily: 'OpenSans',
                    fontSize: 14,
                    spacing: 0.1,
                    borderWidth: 2,
                    borderColor: "rgba(180,180,180, 0.15)"
                },
            ],
        },
        options: {
            maintainAspectRatio: false,
            responsive: true,
            responsiveAnimationDuration: 400,
            legend: {
                display: false,
            },
            tooltips: {
                callbacks: {
                    title: function (item, data) {
                        return dataset[item[0].index].label;
                    },
                    label: function (item, data) {
                        return dataset[item.index].human_size;
                    }
                }
            },
            onClick: function (e, elements) {
                if (elements.length === 0) {
                    return;
                }
                selectAtIndex(elements[0]._index);
            }
        }
    });
}

function toggleDarkMode() {
    dark_mode_enabled = !dark_mode_enabled;
    document.cookie = "darkmode=" + (dark_mode_enabled ? '1' : '0') + "; path=/; expires=Thu, 1 Sep 2050 12:00:00 UTC";
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
    let data = 'action=add_user&username=' + encodeURIComponent(username) + '&password=' + encodeURIComponent(password);
    ajaxCallFromButton(button, 'samba', data, 'Creating...', 'User Created', 'Reloading...', function (data, $button) {
        $button.prop('disabled', true);
        location.reload();
    }, 3);
}

function updateSambaSharePath(el) {
    let share_name = $(el).val();
    let $el_path = $('[name=samba_share_path]');
    let path = $el_path.val().replace('...', share_name);
    $el_path.val(path);
}

function addSambaShare(button) {
    let $button = $(button);
    let $modal = $button.closest('.modal');
    let share_name = $modal.find('[name=samba_share_name]').val();
    let share_path = $modal.find('[name=samba_share_path]').val();
    let share_options = $modal.find('[name=samba_share_options]').val();
    let data = 'action=add_share&name=' + encodeURIComponent(share_name) + '&path=' + encodeURIComponent(share_path) + '&options=' + encodeURIComponent(share_options);
    ajaxCallFromButton(button, 'samba', data, 'Creating...', 'Share Created', 'Reloading...', function (data, $button) {
        $button.prop('disabled', true);
        location.reload();
    }, 3);
}

function getPageTitle() {
    let page_title = ['Greyhole Admin'];

    [$active_page_link, $sub_page_active_link] = getActiveLinks();

    let page_name = $active_page_link.text();
    page_title.push(page_name);

    if ($sub_page_active_link.length) {
        let subpage_name = $sub_page_active_link.text();
        page_title.push(subpage_name);
    }

    return page_title.join(' | ');
}

function getActiveLinks() {
    let $active_page_link = $('.nav[data-name=page] .nav-link.active');
    let $visible_content = $($active_page_link.attr('href'));
    let $sub_page_active_link = $visible_content.find('.nav .nav-link.active');
    return [$active_page_link, $sub_page_active_link];
}

var skip_changed_tab_event = false;
function changedTab(el, first, replace) {
    if (skip_changed_tab_event) {
        skip_changed_tab_event = false;
        return;
    }

    // console.log("changedTab()", $(el).prop('id'));

    $(el).blur();

    [$active_page_link, $sub_page_active_link] = getActiveLinks();

    let selected_tab = $active_page_link.attr('id');
    let selected_tabs = [selected_tab];
    let url = './?page=' + encodeURIComponent($active_page_link.attr('id'));
    if ($sub_page_active_link.length) {
        let param_name = $sub_page_active_link.closest('.nav').data('name');
        url += '&' + param_name + '=' + encodeURIComponent($sub_page_active_link.attr('id'));
        selected_tabs.push($sub_page_active_link.attr('id'));
    }

    if (!first) {
        history.pushState({selected_tabs: selected_tabs}, null, url);
    }

    let title = getPageTitle();
    if (document.title !== title) {
        document.title = title;
    }
    if (replace) {
        history.replaceState({selected_tabs: selected_tabs}, null, url);
    }

    parse_url_params();

    if ($(el).prop('id') in tab_changed_functions) {
        tab_changed_functions[$(el).prop('id')]();
    }
}

function donate() {
    $('#id_l1_ghconfig_tab').tab('show'); // Greyhole Config
    $('#id_l2_ghconfig_794df3791a8c800841516007427a2aa3_tab').tab('show'); // License
}

function goto_remove_drive() {
    $('#id_l1_actions_tab').tab('show'); // Actions
    $('#id_l2_actions_removedrive_tab').tab('show'); // Remove Drive
}

function donationComplete(el) {
    let $el = $(el);
    let email = $el.val();
    ajaxCallFromButton(button, 'donate', 'email=' + encodeURIComponent(email), 'Saving...', null, 'Saved',  function (data, $button) {
        if ($el) {
            $el.attr('data-toggle', 'tooltip').attr('data-placement', 'bottom').attr('title', 'Thank you!').tooltip({trigger: 'manual'}).tooltip('show');
            setTimeout(function() { $el.tooltip('hide'); location.reload(); }, 3*1000);
        }
    }, 0);
}

function checkSambaConfig() {
    let wide_link_config = $('[name=' + $.escapeSelector('smb.conf:[global]wide_links') + ']:checked').val();
    if (wide_link_config === 'no') {
        $('[name=' + $.escapeSelector('smb.conf:[global]wide_links') + ']').parent('label').removeClass('btn-outline-primary').addClass('btn-outline-danger');
    } else {
        $('[name=' + $.escapeSelector('smb.conf:[global]wide_links') + ']').parent('label').removeClass('btn-outline-danger').addClass('btn-outline-primary');
    }

    let unix_extensions_config = $('[name=' + $.escapeSelector('smb.conf:[global]unix_extensions') + ']:checked').val();
    let allow_insecure_wide_links_config = $('[name=' + $.escapeSelector('smb.conf:[global]allow_insecure_wide_links') + ']:checked').val();
    if (unix_extensions_config === 'yes' && allow_insecure_wide_links_config === 'no') {
        $('[name=' + $.escapeSelector('smb.conf:[global]unix_extensions') + ']').parent('label').removeClass('btn-outline-primary').addClass('btn-outline-danger');
        $('[name=' + $.escapeSelector('smb.conf:[global]allow_insecure_wide_links') + ']').parent('label').removeClass('btn-outline-primary').addClass('btn-outline-danger');
    } else {
        $('[name=' + $.escapeSelector('smb.conf:[global]unix_extensions') + ']').parent('label').removeClass('btn-outline-danger').addClass('btn-outline-primary');
        $('[name=' + $.escapeSelector('smb.conf:[global]allow_insecure_wide_links') + ']').parent('label').removeClass('btn-outline-danger').addClass('btn-outline-primary');
    }
}

function continueInstall(button, current_step) {
    let data = '';

    if (current_step === 4) {
        data = '&host=' + encodeURIComponent($('#inputdb_host').val());
        data += '&root_pwd=' + encodeURIComponent($('#inputdb_root_password').val());
    }

    ajaxCallFromButton(button, 'install', 'step=' + encodeURIComponent(current_step) + data, 'Loading...', null, 'Continuing...', function (data, $button) {
        $button.prop('disabled', true);
        location.href = data.next_page;
    }, 0);
}

function parseParams(params) {
    let parsedParams = {};
    params.split("&").forEach(function (pair) {
        if (pair === "") return;
        var parts = pair.split("=");
        parsedParams[parts[0]] = parts[1] && decodeURIComponent(parts[1].replace(/\+/g, " "));
    });
    return parsedParams;
}

function getFsckParams() {
    let params = {};
    let s = $('#id_l2_actions_fsck').find('input, select').serialize();
    let parsedParams = parseParams(s);
    for (let name in parsedParams) {
        let value = parsedParams[name];
        if (name === 'walk-metadata-store') {
            name = 'dont-walk-metadata-store';
            value = (value === 'yes' ? 'no' : 'yes');
        }
        params[name] = value;
    }
    return params;
}

function confirmRemoveDrive() {
    let drive = $('#inputremove_drive').val();
    if (drive === '0') {
        alert('meh!');
        return;
    }

    $.ajax({
        type: 'POST',
        url: './?ajax=pre-remove-drive&drive=' + encodeURIComponent(drive),
        success: function(data, textStatus, jqXHR) {
            if (data.result === 'success') {
                if (data.drive_is_available) {
                    $('[name=drive_is_available][value=yes]').prop('checked', true).parent().addClass('active');
                    $('[name=drive_is_available][value=no]').prop('checked', false).parent().removeClass('active');
                } else {
                    $('[name=drive_is_available][value=no]').prop('checked', true).parent().addClass('active');
                    $('[name=drive_is_available][value=yes]').prop('checked', false).parent().removeClass('active');
                }
                $('#remove-drive-preparing').addClass('d-none');
                $('#remove-drive-ready').removeClass('d-none');
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

function startRemoveDrive(button) {
    let drive = $('#inputremove_drive').val();
    let drive_is_available = $('[name=drive_is_available]:checked').val();
    ajaxCallFromButton(button, 'remove_drive', 'drive=' + encodeURIComponent(drive) + '&drive_is_available=' + drive_is_available, 'Starting drive removal...', 'Started drive removal', 'Reloading...', function (data, $button) {
        $('#remove-drive-ready').addClass('d-none');
        $('#remove-drive-done').removeClass('d-none');
        let body = $(button).closest('.modal-content').find('.modal-body');
        body.text(data.text);
        body.html(body.html().replace(/\n/g, "<br>"));
    }, 0);
}

function confirmFsckCommand() {
    let params = getFsckParams();
    let command = "greyhole --fsck ";
    for (let k in params) {
        let v = params[k];
        if (k === 'dir') {
            if (v !== '') {
                command += "--dir=" + v;
            }
        } else if (v === 'yes') {
            command += "--" + k + " ";
        }
    }
    $('#modal-confirm-fsck code').text(command);
}

function startFsck(button) {
    ajaxCallFromButton(button, 'fsck', getFsckParams(), 'Starting fsck...', 'Started fsck', 'Reloading...', function (data, $button) {
        $button.prop('disabled', true);
        location.href = './';
    }, 3);
}

function cancelFsck(button) {
    ajaxCallFromButton(button, 'fsck', 'action=cancel', 'Cancelling...', 'fsck Cancelled', 'Reloading...', function (data, $button) {
        $button.prop('disabled', true);
        location.href = './';
    }, 3);
}

function startBalance(button) {
    ajaxCallFromButton(button, 'balance', 'action=start', 'Starting...', 'Balance started', 'Reloading...', function (data, $button) {
        $button.prop('disabled', true);
        location.href = './';
    }, 3);
}

function cancelBalance(button) {
    ajaxCallFromButton(button, 'balance', 'action=cancel', 'Cancelling...', 'Balance cancelled', 'Reloading...', function (data, $button) {
        $button.prop('disabled', true);
        location.href = './';
    }, 3);
}

function emptyTrash(button) {
    ajaxCallFromButton(button, 'trash', 'action=empty', 'Emptying...', 'Trash emptied', 'Reloading...', function (data, $button) {
        $button.prop('disabled', true);
        location.reload();
    }, 3);
}

function colorizeTrashContent() {
    let max = 0, min = 9999999999999;
    let $els = $('#trash-content .colorize');
    $els.each(function () {
        let value = $(this).data('value');
        if (value > max) {
            max = value;
        }
        if (value < min) {
            min = value;
        }
    });
    $els.each(function () {
        let value = $(this).data('value');
        $(this).css('color', getTreemapColor(value, max, min));
    });
}

function pauseDaemon(button) {
    ajaxCallFromButton(button, 'pause', 'action=pause', 'Pausing...', 'Daemon paused', 'Reloading...', function (data, $button) {
        $button.prop('disabled', true);
        location.reload();
    }, 3);
}

function resumeDaemon(button) {
    ajaxCallFromButton(button, 'pause', 'action=resume', 'Resuming...', 'Daemon resumed', 'Reloading...', function (data, $button) {
        $button.prop('disabled', true);
        location.reload();
    }, 3);
}

let status_logs_timer;
function tailStatusLogs(button) {
    if (status_logs_timer) {
        clearInterval(status_logs_timer);
    }
    if ($(button).prop('checked')) {
        loadStatusLogs();
        status_logs_timer = setInterval(loadStatusLogs, 10*1000);
    }
}
defer(function(){ tailStatusLogs($('#tail-status-log').prop('checked', true)); });

function loadStatus() {
    $.ajax({
        type: 'POST',
        url: './?ajax=get_status',
        success: function(data, textStatus, jqXHR) {
            if (data.result === 'success') {
                $('.daemon-status').hide();
                $('#daemon-status-' + data.daemon_status).show();
                if (data.daemon_status === 'running') {
                    $('#daemon-status-running').html(data.status_text);
                }
                if (data.current_action === 'balance') {
                    $('#id_l2_status_balance_tab').show();
                } else {
                    $('#id_l2_status_balance_tab').hide();
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

function loadStatusLogs() {
    if (!('page' in urlParams) || urlParams.page === 'id_l1_status_tab') {
        loadStatus();
    }
    if (!('page_status' in urlParams) || urlParams.page_status === 'id_l2_status_balance_tab') {
        loadStatusBalanceReport();
        return;
    }
    if (!('page_status' in urlParams) || urlParams.page_status !== 'id_l2_status_logs_tab') {
        // console.log("Skipping loadStatusLogs() because Logs tab is not visible.");
        return;
    }
    $.ajax({
        type: 'POST',
        url: './?ajax=get_status_logs',
        success: function(data, textStatus, jqXHR) {
            if (data.result === 'success') {
                let $container = $('#status_logs');
                $container.text('');
                for (let log of data.logs) {
                    $container.append($('<div/>').text(log).html() + "<br/>");
                }
                $('#last_action').text('')
                    .html("Last logged action: ")
                    .append($('<strong/>').text(data.last_action));
                if (data.last_action_time !== null) {
                    $('#last_action').append(", on " + data.last_action_time + " (" + data.last_action_time_relative + ")");
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

function loadStatusQueue() {
    var $table = $('#queue');
    var $loading_row = $table.find('.loading');
    $loading_row.show();
    $.ajax({
        type: 'POST',
        url: './?ajax=get_status_queue_content',
        success: function(data, textStatus, jqXHR) {
            if (data.result === 'success') {
                $table.find('tr:not(.loading):not(.header)').detach();
                for (let row of data.rows) {
                    let $tr = $('<tr/>');
                    if (row.share_name === 'Total') {
                        $tr.addClass('total');
                    }
                    $tr.append($('<td/>').text(row.share_name));
                    for (let prop of ['num_writes_pending', 'num_delete_pending', 'num_rename_pending', 'num_fsck_pending']) {
                        let $td = $('<td/>').addClass('num').text(row.queue[prop]);
                        if (row.queue[prop] > 0) {
                            $td.addClass('nonzero');
                        }
                        $tr.append($td);
                    }
                    $table.append($tr);
                }
                $('#num_spooled_ops').text(data.num_spooled_ops);
            } else {
                if (data.result === 'error') {
                    alert(data.message);
                } else {
                    alert("An error occurred. Check your logs for details.");
                }
            }
            $loading_row.hide();
        },
    });
}

function loadStatusPastTasks() {
    $('#past-tasks-table').DataTable().ajax.reload();
}

function loadStatusFsckReport() {
    $('#fsck-report-code').text('Loading...');
    $.ajax({
        type: 'POST',
        url: './?ajax=get_status_fsck_report',
        success: function(data, textStatus, jqXHR) {
            if (data.result === 'success') {
                $('#fsck-report-code').html(data.report_html);
                if (data.show_stop_button) {
                    $('#cancel_fsck_container').show();
                } else {
                    $('#cancel_fsck_container').hide();
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

function loadStatusBalanceReport() {
    let $container = $('#balance_groups');
    $.ajax({
        type: 'POST',
        url: './?ajax=get_status_balance_report',
        success: function(data, textStatus, jqXHR) {
            if (data.result === 'success') {
                if (data.show_stop_button) {
                    $('#cancel_balance_container').show();
                } else {
                    $('#cancel_balance_container').hide();
                }

                for (let group of data.groups) {
                    let $alert = $('<div/>')
                        .addClass('alert alert-info')
                        .prop('role', 'alert')
                        .text('Target free space in ' + group.name + ' storage pool drives: ')
                        .append($('<strong/>').html(group.target_avail_space_html));

                    let $table = $('<table/>').addClass('table-sp-drives')
                        .append(
                            $('<tr/>')
                                .append($('<th/>').text('Path'))
                                .append($('<th/>').text('Needs'))
                                .append($('<th/>').text('Usage'))
                        );

                    for (let sp_drive in group.drives) {
                        let drive_infos = group.drives[sp_drive];
                        let $tr = $('<tr/>');
                        $tr.append($('<td/>').text(sp_drive));
                        $tr.append($('<td/>').html(drive_infos.direction + ' ' + drive_infos.diff_html));
                        let $td = $('<td/>').addClass('sp-bar-td');
                        $td.append(
                            $('<div/>').addClass('sp-bar target has_tooltip')
                                .data('width', drive_infos.target_width)
                                .data('toggle', 'tooltip')
                                .data('placement', 'bottom')
                                .prop('title', drive_infos.tooltip)
                                .tooltip()
                        );
                        $td.append(
                            $('<div/>').addClass('sp-bar has_tooltip')
                                .addClass(drive_infos.direction === '-' ? 'used' : 'free')
                                .text(drive_infos.direction === '-' ? '←' : '→')
                                .data('width', drive_infos.diff_width)
                                .data('toggle', 'tooltip')
                                .data('placement', 'bottom')
                                .prop('title', drive_infos.tooltip)
                                .tooltip()
                        );
                        $tr.append($td);
                        $table.append($tr);
                    }

                    $container.find('.has_tooltip').tooltip('hide'); // Hide any visible tooltip, because it would be stuck visible after detaching it from the document below
                    $container.text('')
                        .append($alert)
                        .append($('<div/>').addClass('col').append($table))
                        .append($('<hr/>'));

                    resizeSPDrivesUsageGraphs();
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

function loadStoragePool() {
    $.ajax({
        type: 'POST',
        url: './?ajax=get_storage_pool',
        success: function(data, textStatus, jqXHR) {
            if (data.result === 'success') {
                let $table = $('#table-sp tbody').text('');
                for (let sp_drive in data.sp_stats) {
                    if (sp_drive === 'Total') {
                        continue;
                    }
                    let drive_infos = data.sp_stats[sp_drive];
                    let $tr = $('<tr/>');
                    $tr.append($('<td/>').text(sp_drive));
                    $tr.append($('<td/>').html(drive_infos.min_free_html));
                    $tr.append($('<td/>').html(drive_infos.size_html));
                    let $td = $('<td/>').addClass('sp-bar-td');
                    $td.append(
                        $('<div/>').addClass('sp-bar used')
                            .data('width', drive_infos.used_width)
                            .data('toggle', 'tooltip').data('placement', 'bottom').prop('title', drive_infos.used_tooltip).tooltip()
                    );
                    $td.append(
                        $('<div/>').addClass('sp-bar trash')
                            .data('width', drive_infos.trash_width)
                            .data('toggle', 'tooltip').data('placement', 'bottom').prop('title', drive_infos.trash_tooltip).tooltip()
                    );
                    $td.append(
                        $('<div/>').addClass('sp-bar free')
                            .data('width', drive_infos.free_width)
                            .data('toggle', 'tooltip').data('placement', 'bottom').prop('title', drive_infos.free_tooltip).tooltip()
                    );
                    $tr.append($td);
                    $table.append($tr);
                }
                resizeSPDrivesUsageGraphs();

                let ctx = document.getElementById('chart_storage_pool').getContext('2d');
                drawPieChartStorage(ctx, data.sp_stats);
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

function loadTrashmanContent() {
    trashmanDataForDir('.');
}

function loadActionsTrashContent() {
    var $table = $('#trash-content');
    var $loading_row = $table.find('.loading');
    $loading_row.show();
    $.ajax({
        type: 'POST',
        url: './?ajax=trash_content',
        success: function(data, textStatus, jqXHR) {
            if (data.result === 'success') {
                $table.find('tr:not(.loading)').detach();
                for (let row of data.sp_stats) {
                    let $tr = $('<tr/>');
                    $tr.append($('<td/>').append('<code/>').text(row.drive));
                    $tr.append($('<td/>').addClass('colorize').data('value', row.trash_size).html(row.trash_size_human));
                    $table.append($tr);
                }
                colorizeTrashContent();
            } else {
                if (data.result === 'error') {
                    alert(data.message);
                } else {
                    alert("An error occurred. Check your logs for details.");
                }
            }
            $loading_row.hide();
        },
    });
}
