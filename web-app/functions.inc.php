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

    if ($current_value === NULL) {
        if (isset($config->current_value)) {
            $current_value = $config->current_value;
        } else {
            $current_value = Config::get($config->name . '_raw') ? Config::get($config->name . '_raw') : Config::get($config->name);
        }
    }

    $input_tag = new InputTag();

    if (!empty($config->help)) {
        $input_tag->attr('aria-describedby', "help_$field_id");
    }

    if (!empty($config->onchange) && is_string($config->onchange)) {
        $input_tag->attr('onchange', $config->onchange);
    } elseif (@$config->onchange !== FALSE) {
        $input_tag->attr('onchange', 'config_value_changed(this)');
    }

    if (@is_array($config->data)) {
        foreach ($config->data as $name => $value) {
            $input_tag->attr("data-$name", $value);
        }
    }

    if (!empty($config->placeholder)) {
        $input_tag->attr('placeholder', $config->placeholder);
    }

    if (empty($config->class)) {
        $config->class = '';
    }

    $input_tag->attr('id', $field_id)
        ->attr('name', $config->name)
        ->attr('class', trim("form-control $config->class"));

    if ($config->type == 'string') {
        if (empty($config->width)) {
            $config->width = 300;
        }
        $html .= $input_tag->textInput($current_value, $config->width)->getHTML();
    }
    elseif ($config->type == 'multi-string') {
        if (empty($config->width)) {
            $config->width = 300;
        }
        $html .= $input_tag->textareaInput(implode("\n", $current_value), $config->width)->getHTML();
    }
    elseif ($config->type == 'integer') {
        $html .= $input_tag->numberInput($current_value, 1)->getHTML();
    }
    elseif ($config->type == 'select' || $config->type == 'toggles') {
        if (!array_contains(array_keys($config->possible_values), $current_value)) {
            $config->possible_values = array_merge([$current_value => $current_value], $config->possible_values);
        }
        if ($config->type == 'toggles') {
            $html .= '<div class="btn-group btn-group-toggle" data-toggle="buttons">';
            foreach ($config->possible_values as $v => $d) {
                $checked = ( $v == $current_value );
                $html .= "<label class='btn btn-outline-primary " . ($checked ? 'active' : '') . "'>" . $input_tag->radioInput($v, $checked)->getHTML() . he($d) . "</label>";
            }
            $html .= '</div>';
        } else {
            $options_html = '';
            foreach ($config->possible_values as $v => $d) {
                $selected = '';
                if ($v == $current_value) {
                    $selected = "selected";
                }
                $options_html .= '<option value="' . he($v) . '" ' . $selected . '>' . he($d) . '</option>';
            }
            $html .= $input_tag->selectInput($options_html)->getHTML();
        }
    }
    elseif ($config->type == 'sp_drives') {
        $options_html = '';
        $config->possible_values = Config::storagePoolDrives();
        foreach ($config->possible_values as $v) {
            $selected = '';
            if (array_contains($current_value, $v)) {
                $selected = "selected";
            }
            $options_html .= '<option value="' . he($v) . '" ' . $selected . '>' . he($v) . '</option>';
        }
        $input_tag->attr('multiple', 'multiple');
        $html .= $input_tag->selectInput($options_html)->getHTML();
    }
    elseif ($config->type == 'bool') {
        $html .= '<div class="btn-group btn-group-toggle" data-toggle="buttons">';
        $html .= "    <label class='btn btn-outline-primary " . ($current_value ? 'active' : '') . "'>" . $input_tag->radioInput('yes', $current_value)->getHTML() . "Yes" . "</label>";
        $html .= "    <label class='btn btn-outline-primary " . (!$current_value ? 'active' : '') . "'>" . $input_tag->radioInput('no', !$current_value)->getHTML() . "No" . "</label>";
        $html .= '</div>';
    }
    elseif ($config->type == 'bytes' || $config->type == 'kbytes') {
        if ($config->type == 'kbytes') {
            $current_value *= 1024;
        }
        $current_value = bytes_to_human($current_value, FALSE);
        $numeric_value = (float) $current_value;

        $html .= $input_tag->numberInput($numeric_value, 1, 0)->attr('style', "max-width: 90px")->getHTML();

        $html .= '</div>';
        $html .= '<div class="col-auto">';

        $options_html = '';
        foreach (['gb' => 'GiB', 'mb' => 'MiB', 'kb' => 'KiB'] as $v => $d) {
            $selected = '';
            if (string_ends_with($current_value, $v)) {
                $selected = "selected";
            }
            if (@$config->shorthand) {
                $v = strtoupper($v[0]);
            }
            $options_html .= '<option value="' . he($v) . '" ' . $selected . '>' . he($d) . '</option>';
        }
        $input_tag->attr('name', "{$config->name}_suffix");
        $html .= $input_tag->selectInput($options_html)->getHTML();
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

function natksort(&$array) {
    $keys = array_keys($array);
    natcasesort($keys);
    foreach ($keys as $k) {
        $new_array[$k] = $array[$k];
    }
    $array = $new_array;
    return true;
}

function last($array) {
    if (empty($array)) {
        return FALSE;
    }
    return array_pop($array);
}

function get_new_share_defaults($all_samba_shares) {
    // Default path for new share: find the most-used path in existing shares
    $prefixes = [];
    $prefix_shares = [];
    $options = [];
    foreach ($all_samba_shares as $share_name => $share_options) {
        if (@$share_options['is_trash'] || empty($share_options[CONFIG_NUM_COPIES . '_raw'])) {
            continue;
        }
        $prefix = dirname($share_options['landing_zone']);
        if (empty($prefix_shares[$prefix])) {
            $prefix_shares[$prefix] = $share_name;
        }
        @$prefixes[$prefix]++;
    }
    // Find the most-used prefix
    asort($prefixes);
    $prefix = last(array_keys($prefixes));
    if ($prefix) {
        $default_path = $prefix . '/...';

        // Default share options: copy the options of the first share we found that uses path = $prefix/...
        $share_name = $prefix_shares[$prefix];
        exec("/usr/bin/testparm -ls --section-name=" . escapeshellarg($share_name), $options);
    } else {
        $default_path = NULL;
    }

    foreach ($options as $i => $option) {
        if (preg_match('/path\s*=/i', $option) || preg_match('/comment\s*=/i', $option) || preg_match('/\[' . $share_name . ']/i', $option)) {
            unset($options[$i]);
            continue;
        }
        if (preg_match('/dfree command\s*=/i', $option)) {
            $option = "dfree command = /usr/bin/greyhole-dfree";
        }
        if (preg_match('/vfs objects\s*=\s*(.*)$/Ui', $option, $re)) {
            $re[1] = str_replace('  ', ' ', str_replace('greyhole', '', $re[1]));
            $option = "vfs objects = greyhole $re[1]";
        }
        $options[$i] = trim($option);
    }
    $options = array_values($options);
    if (empty($options)) {
        // Defaults when no share exist
        $options = explode("\n", "vfs objects = greyhole\ndfree command = /usr/bin/greyhole-dfree\nguest ok = No\nread only = No\navailable = Yes\nbrowseable = Yes\nwritable = Yes\nprintable = No\ncreate mask = 0770\ndirectory mask = 0770");
    }
    return [$default_path, $options];
}

class InputTag {
    private $tag_name;
    private $content;
    private $attributes = [];

    public function radioInput($value, $checked) {
        $this->name('input')
            ->attr('type', 'radio')
            ->attr('autocomplete', 'off')
            ->attr('value', $value);
        if ($checked) {
            $this->attr('checked', 'checked');
        } else {
            $this->removeAttr('checked');
        }
        return $this;
    }

    public function textInput($value, $width) {
        $this->name('input')
            ->attr('type', 'text')
            ->attr('value', $value)
            ->attr('style', "min-width: {$width}px;");
        return $this;
    }

    public function textareaInput($value, $width) {
        $this->name('textarea')
            ->attr('style', "width: {$width}px; height: 150px;")
            ->text($value);
        return $this;
    }

    public function selectInput($options_html) {
        $this->name('select')
            ->html($options_html);
        return $this;
    }

    public function numberInput($value, $step, $min = NULL) {
        $this->name('input')
            ->attr('type', 'number')
            ->attr('value', $value)
            ->attr('step', $step);
        if ($min !== NULL) {
            $this->attr('min', $min);
        }
        return $this;
    }

    public function name($name) {
        $this->tag_name = $name;
        return $this;
    }

    public function text($content) {
        $this->content = he($content);
        return $this;
    }

    public function html($content) {
        $this->content = $content;
        return $this;
    }

    public function attr($name, $value) {
        $this->attributes[$name] = $value;
        return $this;
    }

    public function removeAttr($name) {
        unset($this->attributes[$name]);
        return $this;
    }

    public function getHTML() {
        $html = "<$this->tag_name ";
        foreach ($this->attributes as $name => $value) {
            $html .= he($name) . "='" . he($value) . "' ";
        }
        if (!empty($this->content)) {
            $html .= '>';
            $html .= $this->content;
        }
        if ($this->tag_name == 'input') {
            $html .= ' />' . "\n";
        }
        if (!empty($this->content)) {
            $html .= "</$this->tag_name>" . "\n";
        }
        return $html;
    }
}
