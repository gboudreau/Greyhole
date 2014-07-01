<?php
/*
Copyright 2009-2014 Guillaume Boudreau

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

class CliCommandDefinition {
    protected $longOpt;
    protected $opt;
    protected $paramName;
    private $cliRunnerClass;
    protected $help;

    function __construct($longOpt, $opt, $paramName, $cliRunnerClass, $help) {
        $this->longOpt = $longOpt;
        $this->opt = $opt;
        $this->paramName = $paramName;
        $this->cliRunnerClass = $cliRunnerClass;
        $this->help = $help;
    }

    public function getNewRunner($options) {
        if (empty($this->cliRunnerClass)) {
            return FALSE;
        }
        $ref = new ReflectionClass($this->cliRunnerClass);
        return $ref->newInstance($options, $this);
    }
    
    public function getOpt() {
        return $this->opt;
    }
    
    public function getLongOpt() {
        return $this->longOpt;
    }
    
    public function paramSpecified($command_line_options) {
        $simple_opt = str_replace(':', '', $this->opt);
        $simple_long_opt = str_replace(':', '', $this->longOpt);

        $keys = array_keys($command_line_options);
        if (array_contains($keys, $simple_opt)) {
            if (empty($command_line_options[$simple_opt])) {
                return TRUE;
            }
            return $command_line_options[$simple_opt];
        }
        if (array_contains($keys, $simple_long_opt)) {
            if (empty($command_line_options[$simple_long_opt])) {
                return TRUE;
            }
            return $command_line_options[$simple_long_opt];
        }
        return FALSE;
    }
    
    public function getUsage() {
        if (empty($this->help)) {
            return '';
        }

        $simple_opt = str_replace(':', '', $this->opt);
        $simple_long_opt = str_replace(':', '', $this->longOpt);

        $full_width = 80;
        $prefix_length = 24;
        $padded_newline = "\n" . str_repeat(' ', $prefix_length);
        
        $prefix = sprintf("%-" . $prefix_length . "s", "  -$simple_opt, --$simple_long_opt" . (!empty($this->paramName) ? $this->paramName : ''));
        if (strlen($prefix) > $prefix_length) {
            $prefix .= $padded_newline;
        }
        $help = wordwrap(str_replace("\n", $padded_newline, $this->help), $full_width-$prefix_length, $padded_newline);

        return $prefix . $help . "\n";
    }
}

?>
