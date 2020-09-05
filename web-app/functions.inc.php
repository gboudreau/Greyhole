<?php

function he($string) {
    return htmlentities($string, ENT_QUOTES|ENT_HTML401);
}

function phe($string) {
    echo he($string);
}

function get_config_html($config, $current_value = NULL, $fixed_width_label = TRUE) {
    $config = (object) $config;
    $html = '';
    if ($config->type == 'group') {
        $html .= "<div class='input_group mt-4'>";
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
        $config->possible_values = array_merge($config->possible_values, array_combine(DateTimeZone::listIdentifiers(), DateTimeZone::listIdentifiers()));
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

    $help = !empty($config->help) ? 'aria-describedby="help_' . he($field_id) . '"' : '';

    if ($current_value === NULL) {
        if (isset($config->current_value)) {
            $current_value = $config->current_value;
        } else {
            $current_value = Config::get($config->name . '_raw') ? Config::get($config->name . '_raw') : Config::get($config->name);
        }
    }

    $onchange = 'onchange="config_value_changed(this)"';
    if (@$config->onchange === FALSE) {
        $onchange = '';
    } elseif (!empty($config->onchange) && is_string($config->onchange)) {
        $onchange = 'onchange="' . he($config->onchange) . '"';
    }

    if ($config->type == 'string') {
        $html .= '<input class="form-control ' . (!empty($config->class) ? $config->class : '') . 'l" type="text" id="' . he($field_id) . '" name="' . he($config->name) . '" value="' . he($current_value) . '" ' . $onchange . ' style="min-width: 300px;" ' . $help . ' placeholder="' . (!empty($config->placeholder) ? he($config->placeholder) : '') . '" />';
    }
    elseif ($config->type == 'multi-string') {
        $html .= '<textarea class="form-control ' . (!empty($config->class) ? $config->class : '') . '" id="' . he($field_id) . '" name="' . he($config->name) . '" ' . $onchange . ' style="width: 300px; height: 150px" ' . $help . '>';
        $html .= implode("\n", $current_value);
        $html .= '</textarea>';
    }
    elseif ($config->type == 'integer') {
        $html .= '<input class="form-control ' . (!empty($config->class) ? $config->class : '') . '" type="number" step="1" id="' . he($field_id) . '" name="' . he($config->name) . '" value="' . he($current_value) . '" ' . $onchange . ' ' . $help . ' />';
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
                $html .= '<input class="' . (!empty($config->class) ? $config->class : '') . '" type="radio" name="' . he($config->name) . '" id="' . he($field_id) . '" value="' . he($v) . '" autocomplete="off" ' . $onchange . ' ' . ($selected ? 'checked' : '') . ' ' . $help . '>' . he($d);
                $html .= '</label>';
            }
            $html .= '</div>';
        } else {
            $html .= '<select class="form-control ' . (!empty($config->class) ? $config->class : '') . '" id="' . he($field_id) . '" name="' . he($config->name) . '" ' . $onchange . ' ' . $help . '>';
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
        $html .= '<select class="form-control ' . (!empty($config->class) ? $config->class : '') . '" id="' . he($field_id) . '" name="' . he($config->name) . '" ' . $onchange . ' multiple ' . $help . '>';
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
        $html .= '<input class="' . (!empty($config->class) ? $config->class : '') . '" type="radio" name="' . he($config->name) . '" id="' . he($field_id) . '" value="yes" autocomplete="off" ' . $onchange . ' ' . ($current_value ? 'checked' : '') . ' ' . $help . '>Yes';
        $html .= '</label>';
        $html .= '<label class="btn btn-outline-primary ' . (!$current_value ? 'active' : '') . '">';
        $html .= '<input class="' . (!empty($config->class) ? $config->class : '') . '" type="radio" name="' . he($config->name) . '" id="' . he($field_id) . '" value="no" autocomplete="off" ' . $onchange . ' ' . (!$current_value ? 'checked' : '') . '>No';
        $html .= '</label>';
        $html .= '</div>';
    }
    elseif ($config->type == 'bytes' || $config->type == 'kbytes') {
        if ($config->type == 'kbytes') {
            $current_value *= 1024;
        }
        $current_value = bytes_to_human($current_value, FALSE);
        $numeric_value = (float) $current_value;
        $html .= '<input class="form-control ' . (!empty($config->class) ? $config->class : '') . '" type="number" step="1" min="0" id="' . he($field_id) . '" name="' . he($config->name) . '" ' . $onchange . ' value="' . he($numeric_value) .'" style="max-width: 90px" ' . $help . '>';
        $html .= '</div>';
        $html .= '<div class="col-auto">';
        $html .= '<select class="form-control ' . (!empty($config->class) ? $config->class : '') . '" name="' . he($config->name) . '_suffix" ' . $onchange . '>';
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

    if (!empty($config->help)) {
        $html .= '<div style="margin-top: -8px; margin-bottom: 15px"><small id="help_' . he($field_id) . '" class="form-text text-muted">' . he($config->help) . '</small></div>';
    }

    return $html . ' ';
}
