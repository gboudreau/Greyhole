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

    protected static $hooks = array();

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
        if (array_contains(FileHook::getEventTypes(), $event_type)) {
            $hook = new FileHook($event_type, $script);
        } elseif (array_contains(LogHook::getEventTypes(), $event_type)) {
            $hook = new LogHook($event_type, $script);
        } else {
            Log::warn("Unknown hook event type '$event_type'; ignoring.", Log::EVENT_CODE_HOOK_NOT_EXECUTABLE);
            return;
        }
        static::$hooks[$event_type][] = $hook;
    }

    public static function hasHookForEvent($event_type) {
        return !empty(static::$hooks[$event_type]);
    }

    /**
     * @param string      $event_type
     * @param HookContext $context
     */
    protected static function _trigger($event_type, $context) {
        if (!isset(static::$hooks[$event_type])) {
            return;
        }
        $hooks = static::$hooks[$event_type];
        if (!is_array($hooks)) {
            return;
        }
        foreach ($hooks as $hook) {
            Log::debug("Calling external hook $hook->script for event $hook->event_type ...");
            exec(escapeshellarg($hook->script) . " " . implode(' ', $hook->getArgs($context)) . " 2>&1", $output, $result_code);
            if (!empty($output)) {
                foreach($output as $line) {
                    Log::debug("  $line");
                }
            }
            if ($result_code === 0) {
                Log::debug("External hook exited with status code $result_code.");
            } else {
                /** @noinspection PhpStatementHasEmptyBodyInspection */
                if ($hook->event_type == LogHook::EVENT_TYPE_WARNING) {
                    // Don't start an infinite loop!
                } else {
                    Log::warn("External hook $hook->script exited with status code $result_code.", LogHook::EVENT_CODE_HOOK_NON_ZERO_EXIT_CODE_IN_WARN);
                }
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
    public $from_path_on_share;
    public function __construct($share, $path_on_share, $from_path_on_share = NULL) {
        $this->share = $share;
        $this->path_on_share = $path_on_share;
        $this->from_path_on_share = $from_path_on_share;
    }
}

class FileHook extends Hook
{
    const EVENT_TYPE_CREATE = 'create';
    const EVENT_TYPE_EDIT   = 'edit';
    const EVENT_TYPE_RENAME = 'rename';
    const EVENT_TYPE_DELETE = 'delete';
    const EVENT_TYPE_MKDIR  = 'mkdir';
    const EVENT_TYPE_RMDIR  = 'rmdir';

    public static function trigger($event_type, $share, $path_on_share, $from_path_on_share = NULL) {
        Hook::_trigger($event_type, new FileHookContext($share, $path_on_share, $from_path_on_share));
    }

    /**
     * @param FileHookContext $context
     * @return array
     */
    protected function getArgs($context) {
        $args = array(
            escapeshellarg($this->event_type),
            escapeshellarg($context->share),
            escapeshellarg($context->path_on_share)
        );
        if (!empty($context->from_path_on_share)) {
            $args[] = escapeshellarg($context->from_path_on_share);
        }
        return $args;
    }

    public static function getEventTypes() {
        return array(
            static::EVENT_TYPE_CREATE,
            static::EVENT_TYPE_EDIT,
            static::EVENT_TYPE_RENAME,
            static::EVENT_TYPE_DELETE,
            static::EVENT_TYPE_MKDIR,
            static::EVENT_TYPE_RMDIR
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
    const EVENT_TYPE_WARNING  = 'warning';
    const EVENT_TYPE_ERROR    = 'error';
    const EVENT_TYPE_CRITICAL = 'critical';
    const EVENT_TYPE_IDLE     = 'idle';
    const EVENT_TYPE_NOT_IDLE = 'not_idle';
    const EVENT_TYPE_FSCK     = 'fsck';

    const EVENT_CODE_HOOK_NON_ZERO_EXIT_CODE_IN_WARN = 1; // Used to prevent infinite loop, when logging a WARNING from a LogHook!

    public static function trigger($event_type, $event_code, $log) {
        Hook::_trigger($event_type, new LogHookContext($event_code, $log));
    }

    /**
     * @param LogHookContext $context
     * @return array
     */
    protected function getArgs($context) {
        return array(
            escapeshellarg($this->event_type),
            escapeshellarg($context->event_code),
            escapeshellarg($context->log)
        );
    }

    public static function getEventTypes() {
        return array(
            static::EVENT_TYPE_WARNING,
            static::EVENT_TYPE_ERROR,
            static::EVENT_TYPE_CRITICAL,
            static::EVENT_TYPE_IDLE,
            static::EVENT_TYPE_NOT_IDLE,
            static::EVENT_TYPE_FSCK
        );
    }
}

?>
