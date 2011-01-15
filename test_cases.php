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
	'share_dir' => '/mnt/hdd2/TimeMachine_share_test/',
	'pool_dirs' => array(
		'/mnt/hdd0/gh/TimeMachine',
		'/mnt/hdd2/gh/TimeMachine',
		'/mnt/hdd3/gh/TimeMachine',
		'/mnt/hdd4/gh/TimeMachine',
		'/mnt/hdd5/gh/TimeMachine',
		'/mnt/hdd6/gh/TimeMachine',
		'/mnt/hdd7/gh/TimeMachine',
	),

	'dont_wait' => FALSE,
	
	// Run only a specific test
	// 'run_only' => (object) array('test_name' => 'double file rename after dir rename', 'start_with_run_num' => 1),
);

$num_test = 1;

chdir($config->test_dir);

/*** Tests ***/

$tests = array(
	(object) array(
		'name' => 'file creation',
		'repetitions' => 2,
		'code' => function($run_num) { $i = 1;
			file_put_contents('file1', 'a');
			wait($i++, $run_num);

			$ok = file_get_contents('file1') == 'a';

			// Cleanup
			unlink('file1');
			return $ok;
		}
	),

	(object) array(
		'name' => 'file deletion',
		'repetitions' => 2,
		'code' => function($run_num) { $i = 1;
			file_put_contents('file1', 'a');
			wait($i++, $run_num);

			unlink('file1');

			$ok = !file_exists('file1');
			wait();
			$ok &= !file_exists('file1');
			return $ok;
		}
	),

	(object) array(
		'name' => 'file rename',
		'repetitions' => 2,
		'code' => function($run_num) { $i = 1;
			file_put_contents('file1', 'a');
			wait($i++, $run_num);

			rename('file1', 'file2');

			$ok = file_get_contents('file2') == 'a';
			wait();
			$ok &= file_get_contents('file2') == 'a';
			unlink('file2');
			$ok &= !file_exists('file2');
			return $ok;
		}
	),

	(object) array(
		'name' => 'file rename - back and forth',
		'repetitions' => 4,
		'code' => function($run_num) { $i = 1;
			file_put_contents('file1', 'a');
			wait($i++, $run_num);

			rename('file1', 'file2');
			wait($i++, $run_num);
			rename('file2', 'file1');

			$ok = file_get_contents('file1') == 'a';
			wait();
			$ok &= file_get_contents('file1') == 'a';
			unlink('file1');
			$ok &= !file_exists('file1');
			return $ok;
		}
	),

	(object) array(
		'name' => 'file rename - using just deleted filename',
		'repetitions' => 8,
		'code' => function($run_num) { $i = 1;
			file_put_contents('file1', 'a');
			wait($i++, $run_num);
			file_put_contents('file2', 'b');
			wait($i++, $run_num);

			unlink('file2');
			wait($i++, $run_num);
			rename('file1', 'file2');

			$ok = file_get_contents('file2') == 'a';
			wait();
			$ok &= file_get_contents('file2') == 'a';
			unlink('file2');
			$ok &= !file_exists('file2');
			return $ok;
		}
	),

	(object) array(
		'name' => 'file rename - back and forth - using just deleted filename',
		'repetitions' => 16,
		'code' => function($run_num) { $i = 1;
			file_put_contents('file1', 'a');
			wait($i++, $run_num);
			file_put_contents('file2', 'b');
			wait($i++, $run_num);

			unlink('file2');
			wait($i++, $run_num);
			rename('file1', 'file2');
			wait($i++, $run_num);
			rename('file2', 'file1');

			$ok = file_get_contents('file1') == 'a';
			wait();
			$ok &= file_get_contents('file1') == 'a';
			unlink('file1');
			$ok &= !file_exists('file1');
			return $ok;
		}
	),

	(object) array(
		'name' => 'file rename - back and forth - with 3 files',
		'repetitions' => 16,
		'code' => function($run_num) { $i = 1;
			file_put_contents('file1', 'a');
			wait($i++, $run_num);

			file_put_contents('file2', 'b');
			wait($i++, $run_num);
			rename('file1', 'file3');
			wait($i++, $run_num);
			rename('file2', 'file1');
			wait($i++, $run_num);
			unlink('file3');

			$ok = file_get_contents('file1') == 'b';
			$ok &= !file_exists('file2');
			$ok &= !file_exists('file3');
			wait();
			$ok &= file_get_contents('file1') == 'b';
			$ok &= !file_exists('file3');
			unlink('file1');
			$ok &= !file_exists('file1');
			return $ok;
		}
	),

	(object) array(
		'name' => 'file rename - using just deleted filename - inside subdir',
		'repetitions' => 16,
		'code' => function($run_num) { $i = 1;
			mkdir('dir1');
			file_put_contents('dir1/file1', 'a');
			wait($i++, $run_num);
			file_put_contents('file2', 'b');
			wait($i++, $run_num);

			unlink('dir1/file1');
			wait($i++, $run_num);
			rename('file2', 'dir1/file1');

			$ok = file_get_contents('dir1/file1') == 'b';
			wait();
			$ok &= file_get_contents('dir1/file1') == 'b';
			unlink('dir1/file1');
			$ok &= !file_exists('dir1/file1');
			rmdir('dir1');
			$ok &= !file_exists('dir1');
			return $ok;
		}
	),

	(object) array(
		'name' => 'file rename - back and forth - using just deleted filename - inside subdir',
		'repetitions' => 16,
		'code' => function($run_num) { $i = 1;
			mkdir('dir1');
			file_put_contents('dir1/file1', 'a');
			wait($i++, $run_num);
			file_put_contents('file2', 'b');
			wait($i++, $run_num);

			unlink('dir1/file1');
			wait($i++, $run_num);
			rename('file2', 'dir1/file1');
			wait($i++, $run_num);
			rename('dir1/file1', 'file2');

			$ok = file_get_contents('file2') == 'b';
			wait();
			$ok &= file_get_contents('file2') == 'b';
			unlink('file2');
			$ok &= !file_exists('file2');
			rmdir('dir1');
			$ok &= !file_exists('dir1');
			return $ok;
		}
	),

	(object) array(
		'name' => 'file rename - using just deleted directory name',
		'repetitions' => 16,
		'code' => function($run_num) { $i = 1;
			mkdir('dir1');
			file_put_contents('dir1/file1', 'a');
			wait($i++, $run_num);
			file_put_contents('file2', 'b');
			wait($i++, $run_num);

			unlink('dir1/file1');
			wait($i++, $run_num);
			rmdir('dir1');
			wait($i++, $run_num);
			rename('file2', 'dir1');

			$ok = file_get_contents('dir1') == 'b';
			wait();
			$ok &= file_get_contents('dir1') == 'b';
			unlink('dir1');
			$ok &= !file_exists('dir1');
			return $ok;
		}
	),

	(object) array(
		'name' => 'file rename - back and forth - using just deleted directory name',
		'repetitions' => 32,
		'code' => function($run_num) { $i = 1;
			mkdir('dir1');
			file_put_contents('dir1/file1', 'a');
			wait($i++, $run_num);
			file_put_contents('file2', 'b');
			wait($i++, $run_num);

			unlink('dir1/file1');
			wait($i++, $run_num);
			rmdir('dir1');
			wait($i++, $run_num);
			rename('file2', 'dir1');
			wait($i++, $run_num);
			rename('dir1', 'file2');

			$ok = file_get_contents('file2') == 'b';
			wait();
			$ok &= file_get_contents('file2') == 'b';
			unlink('file2');
			$ok &= !file_exists('file2');
			return $ok;
		}
	),

	(object) array(
		'name' => 'file rename - back and forth - using just deleted directory name - inside subdir',
		'repetitions' => 128,
		'code' => function($run_num) { $i = 1;
			mkdir('dir1');
			file_put_contents('dir1/file1', 'a');
			wait($i++, $run_num);
			mkdir('dir2');
			file_put_contents('dir2/file2', 'b');
			wait($i++, $run_num);

			unlink('dir1/file1');
			wait($i++, $run_num);
			rename('dir2/file2', 'dir1/file1');
			wait($i++, $run_num);
			rmdir('dir2');
			wait($i++, $run_num);
			rename('dir1/file1', 'dir2');
			wait($i++, $run_num);
			rmdir('dir1');
			wait($i++, $run_num);
			rename('dir2', 'dir1');

			$ok = file_get_contents('dir1') == 'b';
			wait();
			$ok &= file_get_contents('dir1') == 'b';
			unlink('dir1');
			$ok &= !file_exists('dir1');
			return $ok;
		}
	),

	(object) array(
		'name' => 'directory & file rename - dir rename, file move, rmdir',
		'repetitions' => 16,
		'code' => function($run_num) { $i = 1;
			mkdir('dir1');
			mkdir('dir1/dir2');
			file_put_contents('dir1/dir2/file1', 'a');
			wait($i++, $run_num);

			rename('dir1', 'dir3');
			wait($i++, $run_num);
			rename('dir3/dir2/file1', 'dir3/file1');
			wait($i++, $run_num);
			rmdir('dir3/dir2');
			wait($i++, $run_num);

			$ok = file_get_contents('dir3/file1') == 'a';
			wait();
			$ok &= file_get_contents('dir3/file1') == 'a';
			unlink('dir3/file1');
			$ok &= !file_exists('dir3/file1');
			rmdir('dir3');
			$ok &= !file_exists('dir3');
			return $ok;
		}
	),

	(object) array(
		'name' => 'file delete - while fsck runs',
		'repetitions' => 4,
		'code' => function($run_num) { $i = 1;
			file_put_contents('file1', 'a');
			wait($i++, $run_num);
			exec('/usr/bin/greyhole --fsck --dir /mnt/hdd0/shares/TimeMachine/');
			unlink('file1');
			wait($i++, $run_num);

			$ok = !file_exists('file1');
			wait();
			$ok &= !file_exists('file1');
			return $ok;
		}
	),

	(object) array(
		'name' => 'directory rename',
		'repetitions' => 16,
		'code' => function($run_num) { $i = 1;
			mkdir('dir1');
			mkdir('dir2');
			file_put_contents('dir1/file1', 'a');
			wait($i++, $run_num);

			rename('dir1', 'dir3');
			wait($i++, $run_num);
			$ok = file_get_contents('dir3/file1') == 'a';
			wait();
			rename('dir3', 'dir2/dir3');
			wait($i++, $run_num);

			$ok &= file_get_contents('dir2/dir3/file1') == 'a';
			wait();
			unlink('dir2/dir3/file1');
			$ok &= !file_exists('dir2/dir3/file1');
			rmdir('dir2/dir3');
			$ok &= !file_exists('dir2/dir3');
			rmdir('dir2');
			$ok &= !file_exists('dir2');
			return $ok;
		}
	),

	(object) array(
		'name' => 'double file rename after dir rename',
		'repetitions' => 4,
		'code' => function($run_num) { $i = 1;
			mkdir('dir1');
			file_put_contents('dir1/file1', 'a');
			wait($i++, $run_num);

			rename('dir1', 'dir2');
			rename('dir2/file1', 'dir2/file2');
			rename('dir2/file2', 'file2');
			wait($i++, $run_num);

			$ok = file_get_contents('file2') == 'a';
			wait();
			$ok &= file_get_contents('file2') == 'a';
			rmdir('dir2');
			unlink('file2');
			$ok &= !file_exists('dir2');
			$ok &= !file_exists('file2');
			wait();
			$ok &= !file_exists('dir2');
			$ok &= !file_exists('file2');
			return $ok;
		}
	),

	(object) array(
		'name' => 'write file, rename parent dir',
		'repetitions' => 2,
		'code' => function($run_num) { $i = 1;
			mkdir('dir1');
			file_put_contents('dir1/file1', 'a');
			wait($i++, $run_num);
			rename('dir1', 'dir2');

			$ok = file_get_contents('dir2/file1') == 'a';
			wait();
			$ok &= file_get_contents('dir2/file1') == 'a';
			unlink('dir2/file1');
			$ok &= !file_exists('dir2/file1');
			wait();
			$ok &= !file_exists('dir2/file1');
			rmdir('dir2');
			wait();
			return $ok;
		}
	),

	(object) array(
		'name' => 'write file, rename two parent dirs',
		'repetitions' => 4,
		'code' => function($run_num) { $i = 1;
			mkdir('dir1');
			file_put_contents('dir1/file1', 'a');
			wait($i++, $run_num);
			rename('dir1', 'dir2');
			wait($i++, $run_num);
			rename('dir2', 'dir3');

			$ok = file_get_contents('dir3/file1') == 'a';
			wait();
			$ok &= file_get_contents('dir3/file1') == 'a';
			unlink('dir3/file1');
			$ok &= !file_exists('dir3/file1');
			wait();
			$ok &= !file_exists('dir3/file1');
			rmdir('dir3');
			wait();
			return $ok;
		}
	),

	(object) array(
		'name' => 'random back and forth dir & file renames',
		'repetitions' => 512,
		'code' => function($run_num) { $i = 1;
			mkdir('dir1');
			file_put_contents('dir1/file1', 'a');
			wait($i++, $run_num);
			file_put_contents('file2', 'b');
			wait($i++, $run_num);
			mkdir('dir2');
			file_put_contents('dir2/file3', 'c');
			wait($i++, $run_num);

			rename('dir1', 'dir3');
			wait($i++, $run_num);
			rename('dir3/file1', 'dir3/file4');
			wait($i++, $run_num);
			rename('dir3/file4', 'dir2/file4');
			wait($i++, $run_num);
			rename('dir2', 'dir4');
			wait($i++, $run_num);
			rename('dir4/file4', 'dir3/file5');
			wait($i++, $run_num);
			rename('file2', 'dir4/file4');
			wait($i++, $run_num);

			$ok = file_get_contents('dir3/file5') == 'a';
			$ok &= file_get_contents('dir4/file4') == 'b';
			$ok &= file_get_contents('dir4/file3') == 'c';
			wait();
			$ok &= file_get_contents('dir3/file5') == 'a';
			$ok &= file_get_contents('dir4/file4') == 'b';
			$ok &= file_get_contents('dir4/file3') == 'c';
			unlink('dir3/file5');
			unlink('dir4/file4');
			unlink('dir4/file3');
			$ok &= !file_exists('dir3/file5');
			$ok &= !file_exists('dir4/file4');
			$ok &= !file_exists('dir4/file3');
			wait();
			$ok &= !file_exists('dir3/file5');
			$ok &= !file_exists('dir4/file4');
			$ok &= !file_exists('dir4/file3');
			rmdir('dir3');
			rmdir('dir4');
			wait();
			return $ok;
		}
	),

	/*
	(object) array(
		'name' => 'name',
		'repetitions' => 16,
		'code' => function($run_num) { $i = 1;
			// TEST CODE GOES HERE
			return $ok;
		}
	),
	*/
);

// Actual Tests run

foreach ($tests as $test) {
	if (isset($config->run_only) && $config->run_only->test_name != $test->name) {
		continue;
	}
	$start = 1;
	if (isset($config->run_only->start_with_run_num)) {
		$start = $config->run_only->start_with_run_num;
	}
	foreach (range($start, $test->repetitions) as $run_num) {
		check_pool_is_empty();
		$ok = call_user_func($test->code, $run_num);
		print_result($test->name, $ok);
		wait();
		check_pool_is_empty();
	}
}

// Functions

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
			usleep(1000*100); // 100ms
		}
	}
}

function check_pool_is_empty() {
	global $config;
	$left = glob("$config->test_dir/*");
	if (count($left) > 0) {
		echo "Last test left some files in the share ($config->test_dir):\n";
		echo implode("\n", $left) . "\n";
		exit(-1);
	}

	$left = glob("$config->share_dir/*");
	if (count($left) > 0) {
		echo "Last test left some files in the shared directory ($config->share_dir):\n";
		echo implode("\n", $left) . "\n";
		exit(-1);
	}

	foreach ($config->pool_dirs as $dir) {
		$left = glob("$dir/*");
		if (count($left) > 0) {
			echo "Last test left some files in a pool directory ($dir):\n";
			echo implode("\n", $left) . "\n";
			exit(-1);
		}
	}
}
?>
