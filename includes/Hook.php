<?php
/*
Copyright 2017 Guillaume Boudreau

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

abstract class Hook
{
    protected $event_type;
    protected $script;

    private static $hooks = array();

    /**
     * @param string $event_type
     * @param string $script
     */
    public function __construct($event_type, $script) {
        $this->event_type = $event_type;
        $this->script = $script;
    }

    /**
     * @param string $event_type
     * @param string $script
     */
    public static function add($event_type, $script) {
        if (array_contains(array('create', 'edit', 'rename', 'delete', 'mkdir', 'rmdir'), $event_type)) {
            $hook = new FileHook($event_type, $script);
        } else {
            Log::warn("Unknown hook event type '$event_type'; ignoring.");
            return;
        }
        static::$hooks[$event_type][] = $hook;
    }

    /**
     * @param string      $event_type
     * @param HookContext $context
     */
    protected static function _trigger($event_type, $context) {
        $hooks = @static::$hooks[$event_type];
        if (!is_array($hooks)) {
            return;
        }
        foreach ($hooks as $hook) {
            Log::debug("Calling external hook $hook->script for event $hook->event_type ...");
            exec($hook->script . " " . implode(' ', $hook->getArgs($context)) . " 2>&1", $output, $result_code);
            foreach($output as $line) {
                Log::debug("  $line");
            }
            if ($result_code == 0) {
                Log::debug("External hook exited with status code $result_code.");
            } else {
                Log::warn("External hook $hook->script exited with status code $result_code.");
            }
        }
    }

    /**
     * @param HookContext $context
     */
    abstract protected function getArgs($context);
}

interface HookContext {}

class FileHookContext implements HookContext
{
    public $share;
    public $path_on_share;
    public function __construct($share, $path_on_share) {
        $this->share = $share;
        $this->path_on_share = $path_on_share;
    }
}

class FileHook extends Hook
{
    public static function trigger($event_type, $share, $path_on_share) {
        parent::_trigger($event_type, new FileHookContext($share, $path_on_share));
    }

    /**
     * @param FileHookContext $context
     * @return string
     */
    protected function getArgs($context) {
        return array(
            escapeshellarg($this->event_type),
            escapeshellarg($context->share),
            escapeshellarg($context->path_on_share)
        );
    }
}

class LogHookContext implements HookContext
{
    public $event_code;
    public $log;
    public function __construct($event_code, $log) {
        $this->event_code = $event_code;
        $this->log = $log;
    }
}

class LogHook extends Hook
{
    public static function trigger($event_type, $event_code, $log) {
        parent::_trigger($event_type, new LogHookContext($event_code, $log));
    }

    /**
     * @param LogHookContext $context
     * @return string
     */
    protected function getArgs($context) {
        return array(
            escapeshellarg($this->event_type),
            escapeshellarg($context->event_code),
            escapeshellarg($context->log)
        );
    }
}
