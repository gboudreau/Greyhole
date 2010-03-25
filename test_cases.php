#!/usr/bin/php
<?php
/*
Copyright 2010 Guillaume Boudreau

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

$config = (object) array(
	'test_dir' => '/mnt/samba/TimeMachine/',
	'dont_wait' => FALSE
);

$num_test = 1;
function print_result($test, $ok) {
	global $num_test;
	echo date("M ") . sprintf('%2d', (int) date("d")) . date(" H:i:s ");
	echo " Test #" . ($num_test++) . ": $test: " . ($ok ? 'OK' : 'FAILED') . "\n";
	if (!$ok) {
		die("\n");
	}
}

function wait($wait_num=0, $i=0) {
	global $config;
	if ($config->dont_wait) {
		return;
	}
	$wait = $wait_num == 0 || ($i-1)/(pow(2, $wait_num-1)) % 2 == 1;
	if ($wait) {
		$last_line_before = exec('tail -1 /var/log/greyhole.log');
		while (TRUE) {
			$last_line = exec('tail -1 /var/log/greyhole.log');
			if ($last_line_before != $last_line && strpos($last_line, '... Sleeping.') !== FALSE) {
				break;
			}
			sleep(1);
		}
	}
}

chdir($config->test_dir);

/*** Tests ***/

foreach (range(1,2) as $i) { $j = 1;
	file_put_contents('file1', 'a');
	wait($j++, $i);

	$ok = file_get_contents('file1') == 'a';
	print_result('file creation', $ok);
	wait();
}

foreach (range(1,2) as $i) { $j = 1;
	file_put_contents('file1', 'a');
	wait($j++, $i);

	unlink('file1');

	$ok = !file_exists('file1');
	wait();
	$ok &= !file_exists('file1');
	print_result('file deletion', $ok);
	wait();
}

foreach (range(1,2) as $i) { $j = 1;
	file_put_contents('file1', 'a');
	wait($j++, $i);

	rename('file1', 'file2');

	$ok = file_get_contents('file2') == 'a';
	wait();
	$ok &= file_get_contents('file2') == 'a';
	unlink('file2');
	$ok &= !file_exists('file2');
	print_result('file rename', $ok);
	wait();
}

foreach (range(1,4) as $i) { $j = 1;
	file_put_contents('file1', 'a');
	wait($j++, $i);

	rename('file1', 'file2');
	wait($j++, $i);
	rename('file2', 'file1');

	$ok = file_get_contents('file1') == 'a';
	wait();
	$ok &= file_get_contents('file1') == 'a';
	unlink('file1');
	$ok &= !file_exists('file1');
	print_result('file rename - back and forth', $ok);
	wait();
}

foreach (range(1,8) as $i) { $j = 1;
	file_put_contents('file1', 'a');
	wait($j++, $i);
	file_put_contents('file2', 'b');
	wait($j++, $i);

	unlink('file2');
	wait($j++, $i);
	rename('file1', 'file2');

	$ok = file_get_contents('file2') == 'a';
	wait();
	$ok &= file_get_contents('file2') == 'a';
	unlink('file2');
	$ok &= !file_exists('file2');
	print_result('file rename - using just deleted filename', $ok);
	wait();
}

foreach (range(1,16) as $i) { $j = 1;
	file_put_contents('file1', 'a');
	wait($j++, $i);
	file_put_contents('file2', 'b');
	wait($j++, $i);

	unlink('file2');
	wait($j++, $i);
	rename('file1', 'file2');
	wait($j++, $i);
	rename('file2', 'file1');

	$ok = file_get_contents('file1') == 'a';
	wait();
	$ok &= file_get_contents('file1') == 'a';
	unlink('file1');
	$ok &= !file_exists('file1');
	print_result('file rename - back and forth - using just deleted filename', $ok);
	wait();
}

foreach (range(1,16) as $i) { $j = 1;
	mkdir('dir1');
	file_put_contents('dir1/file1', 'a');
	wait($j++, $i);
	file_put_contents('file2', 'b');
	wait($j++, $i);

	unlink('dir1/file1');
	wait($j++, $i);
	rename('file2', 'dir1/file1');

	$ok = file_get_contents('dir1/file1') == 'b';
	wait();
	$ok &= file_get_contents('dir1/file1') == 'b';
	unlink('dir1/file1');
	$ok &= !file_exists('dir1/file1');
	rmdir('dir1');
	$ok &= !file_exists('dir1');
	print_result('file rename - using just deleted filename - inside subdir', $ok);
	wait();
}

foreach (range(1,16) as $i) { $j = 1;
	mkdir('dir1');
	file_put_contents('dir1/file1', 'a');
	wait($j++, $i);
	file_put_contents('file2', 'b');
	wait($j++, $i);

	unlink('dir1/file1');
	wait($j++, $i);
	rename('file2', 'dir1/file1');
	wait($j++, $i);
	rename('dir1/file1', 'file2');

	$ok = file_get_contents('file2') == 'b';
	wait();
	$ok &= file_get_contents('file2') == 'b';
	unlink('file2');
	$ok &= !file_exists('file2');
	rmdir('dir1');
	$ok &= !file_exists('dir1');
	print_result('file rename - back and forth - using just deleted filename - inside subdir', $ok);
	wait();
}

foreach (range(1,16) as $i) { $j = 1;
	mkdir('dir1');
	file_put_contents('dir1/file1', 'a');
	wait($j++, $i);
	file_put_contents('file2', 'b');
	wait($j++, $i);

	unlink('dir1/file1');
	wait($j++, $i);
	rmdir('dir1');
	wait($j++, $i);
	rename('file2', 'dir1');

	$ok = file_get_contents('dir1') == 'b';
	wait();
	$ok &= file_get_contents('dir1') == 'b';
	unlink('dir1');
	$ok &= !file_exists('dir1');
	print_result('file rename - using just deleted directory name', $ok);
	wait();
}

foreach (range(1,32) as $i) { $j = 1;
	mkdir('dir1');
	file_put_contents('dir1/file1', 'a');
	wait($j++, $i);
	file_put_contents('file2', 'b');
	wait($j++, $i);

	unlink('dir1/file1');
	wait($j++, $i);
	rmdir('dir1');
	wait($j++, $i);
	rename('file2', 'dir1');
	wait($j++, $i);
	rename('dir1', 'file2');

	$ok = file_get_contents('file2') == 'b';
	wait();
	$ok &= file_get_contents('file2') == 'b';
	unlink('file2');
	$ok &= !file_exists('file2');
	print_result('file rename - back and forth - using just deleted directory name', $ok);
	wait();
}

foreach (range(1,128) as $i) { $j = 1;
	mkdir('dir1');
	file_put_contents('dir1/file1', 'a');
	wait($j++, $i);
	mkdir('dir2');
	file_put_contents('dir2/file2', 'b');
	wait($j++, $i);

	unlink('dir1/file1');
	wait($j++, $i);
	rename('dir2/file2', 'dir1/file1');
	wait($j++, $i);
	rmdir('dir2');
	wait($j++, $i);
	rename('dir1/file1', 'dir2');
	wait($j++, $i);
	rmdir('dir1');
	wait($j++, $i);
	rename('dir2', 'dir1');

	$ok = file_get_contents('dir1') == 'b';
	wait();
	$ok &= file_get_contents('dir1') == 'b';
	unlink('dir1');
	$ok &= !file_exists('dir1');
	print_result('file rename - back and forth - using just deleted directory name - inside subdir', $ok);
	wait();
}

foreach (range(1,16) as $i) { $j = 1;
	mkdir('dir1');
	mkdir('dir1/dir2');
	file_put_contents('dir1/dir2/file1', 'a');
	wait($j++, $i);

	rename('dir1', 'dir3');
	wait($j++, $i);
	rename('dir3/dir2/file1', 'dir3/file1');
	wait($j++, $i);
	rmdir('dir3/dir2');
	wait($j++, $i);

	$ok = file_get_contents('dir3/file1') == 'a';
	wait();
	$ok &= file_get_contents('dir3/file1') == 'a';
	unlink('dir3/file1');
	$ok &= !file_exists('dir3/file1');
	rmdir('dir3');
	$ok &= !file_exists('dir3');
	print_result('directory & file rename - dir rename, file move, rmdir', $ok);
	wait();
}

foreach (range(1,4) as $i) { $j = 1;
	file_put_contents('file1', 'a');
	wait($j++, $i);
	exec('/usr/bin/greyhole --fsck --dir /mnt/hdd0/shares/TimeMachine/');
	unlink('file1');
	wait($j++, $i);

	$ok = !file_exists('file1');
	wait();
	$ok &= !file_exists('file1');
	print_result('file delete - while fsck runs', $ok);
	wait();
}

foreach (range(1,16) as $i) { $j = 1;
	mkdir('dir1');
	mkdir('dir2');
	file_put_contents('dir1/file1', 'a');
	wait($j++, $i);

	rename('dir1', 'dir3');
	wait($j++, $i);
	$ok = file_get_contents('dir3/file1') == 'a';
	wait();
	rename('dir3', 'dir2/dir3');
	wait($j++, $i);

	$ok &= file_get_contents('dir2/dir3/file1') == 'a';
	wait();
	unlink('dir2/dir3/file1');
	$ok &= !file_exists('dir2/dir3/file1');
	rmdir('dir2/dir3');
	$ok &= !file_exists('dir2/dir3');
	rmdir('dir2');
	$ok &= !file_exists('dir2');
	print_result('directory rename', $ok);
	wait();
}
?>
