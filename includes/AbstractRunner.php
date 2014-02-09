<?php
/*
Copyright 2009-2012 Guillaume Boudreau, Andrew Hopkinson

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

abstract class AbstractRunner {
	
	function __construct() {
	}

	abstract public function run();
	
	// Most commands can be executed only by root.
	// The commands that don't have this requirement will need to extend AbstractAnonymousCliRunner instead of this class.
	public function canRun() {
		if (exec("whoami") != 'root') {
			return FALSE;
		}
		return TRUE;
	}
	
	// Most runners will exit on completion. The daemon is an exception, and will need to override this method to keep running.
	public function finish($returnValue = 0) {
		exit($returnValue);
	}
}

?>
