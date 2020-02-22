#!/usr/bin/php
<?php
/*
Copyright 2010-2020 Guillaume Boudreau

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

require_once __DIR__ . '/includes/Metastores.php';

define('DEBUG', FALSE);

$config = (object) ['share_name' => 'GreyholeTests'];
$config->log_file  = "/home/gb/docker-services/samba-greyhole/_vol/usr-share-greyhole/log/greyhole.log";
$config->test_dir  = "/mnt/samba/$config->share_name/";
$config->share_dir = "/opt/samba-shares/$config->share_name/";
$config->pool_dirs = [
    "/mnt/hdd1/gh/$config->share_name",
    "/mnt/hdd2/gh/$config->share_name",
    "/mnt/hdd3/gh/$config->share_name",
    "/mnt/hdd4/gh/$config->share_name",
    "/mnt/hdd5/gh/$config->share_name",
    "/mnt/hdd6/gh/$config->share_name",
    "/mnt/hdd7/gh/$config->share_name",
    "/mnt/hdd8/gh/$config->share_name",
    "/mnt/hdd9/gh/$config->share_name",
];

//$config->dont_wait = TRUE;

// Run only a specific test
//$config->run_only = (object) ['test_name' => 'random back and forth dir & file renames', 'start_with_run_num' => 26];

$num_test = 1;

chdir($config->test_dir);

/*** Tests ***/

$tests = array(
    (object) array(
        'name' => 'GH-108',
        'repetitions' => 2,
        'code' => function($run_num) { $i = 1;
                mkdir('_UNPACK_dir1');
                file_put_contents('_UNPACK_dir1/file1', 'a');
                wait($i++, $run_num);
                file_put_contents('_UNPACK_dir1/file2', 'b');
                wait($i++, $run_num);
                mkdir('_UNPACK_dir1/dir2');
                file_put_contents('_UNPACK_dir1/dir2/file3', 'c');
                wait($i++, $run_num);

                rename('_UNPACK_dir1', 'dir1');

                $ok = file_get_contents('dir1/file1') == 'a';
                wait();
                $ok &= file_get_contents('dir1/file2') == 'b';
                wait();
                $ok &= file_get_contents('dir1/dir2/file3') == 'c';
                wait();

                $found_in_storage_pool = FALSE;
                global $config;
                foreach ($config->pool_dirs as $sp_drive) {
                    if (file_exists("$sp_drive/dir1/file1")) {
                        $found_in_storage_pool = TRUE;
                        break;
                    }
                }
                $ok &= $found_in_storage_pool;

                $ok &= file_get_contents('dir1/file1') == 'a';
                wait();
                $ok &= file_get_contents('dir1/file2') == 'b';
                wait();
                $ok &= file_get_contents('dir1/dir2/file3') == 'c';
                wait();

                unlink('dir1/file1');
                $ok &= !file_exists('dir1/file1');
                unlink('dir1/file2');
                $ok &= !file_exists('dir1/file2');
                unlink('dir1/dir2/file3');
                $ok &= !file_exists('dir1/dir2/file3');
                rmdir('dir1/dir2');
                $ok &= !file_exists('dir1/dir2');
                rmdir('dir1');
                $ok &= !file_exists('dir1');
                return $ok;
            }
    ),

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
			global $config;
			exec('/usr/bin/greyhole --fsck --dir ' . escapeshellarg("/mnt/hdd0/shares/$config->share_name/"));
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
            logd("  a > dir1/file1");

            _assert('dir1/file1', 'a');
			wait($i++, $run_num);
            _assert('dir1/file1', 'a');

			file_put_contents('file2', 'b');
            logd("  b > file2");

            _assert('file2', 'b');
			wait($i++, $run_num);
            _assert('file2', 'b');

			mkdir('dir2');
			file_put_contents('dir2/file3', 'c');
            logd("  c > dir2/file3");

            _assert('dir2/file3', 'c');
			wait($i++, $run_num);
            _assert('dir2/file3', 'c');

			rename('dir1', 'dir3');
            logd("  dir1 > dir3");

            _assert('dir3/file1', 'a');
            wait($i++, $run_num);
            _assert('dir3/file1', 'a');

			rename('dir3/file1', 'dir3/file4');
            logd("  dir3/file1 > dir3/file4");

            _assert('dir3/file4', 'a');
			wait($i++, $run_num);
            _assert('dir3/file4', 'a');

			rename('dir3/file4', 'dir2/file4');
            logd("  dir3/file4 > dir2/file4");

            _assert('dir2/file4', 'a');
			wait($i++, $run_num);
            _assert('dir2/file4', 'a');

			rename('dir2', 'dir4');
            logd("  dir2 > dir4");

            _assert('dir4/file3', 'c');
            _assert('dir4/file4', 'a');
			wait($i++, $run_num);
            _assert('dir4/file3', 'c');
            _assert('dir4/file4', 'a');

			rename('dir4/file4', 'dir3/file5');
            logd("  dir4/file4 > dir3/file5");

            _assert('dir3/file5', 'a');
			wait($i++, $run_num);
            _assert('dir3/file5', 'a');

			rename('file2', 'dir4/file4');
            logd("  file2 > dir4/file4");

            _assert('dir4/file4', 'b');
			wait($i++, $run_num);
            _assert('dir4/file4', 'b');

            _assert('dir3/file5', 'a');
            _assert('dir4/file4', 'b');
            _assert('dir4/file3', 'c');
			wait();
            _assert('dir3/file5', 'a');
            _assert('dir4/file4', 'b');
            _assert('dir4/file3', 'c');

			unlink('dir3/file5');
			unlink('dir4/file4');
			unlink('dir4/file3');

            _assert('dir3/file5', FALSE);
            _assert('dir4/file4', FALSE);
            _assert('dir4/file3', FALSE);
			wait();
            _assert('dir3/file5', FALSE);
            _assert('dir4/file4', FALSE);
            _assert('dir4/file3', FALSE);

			rmdir('dir3');
			rmdir('dir4');
			wait();
			return TRUE;
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
    global $num_test;
	if (isset($config->run_only) && $config->run_only->test_name != $test->name) {
		continue;
	}
	$start = 1;
	if (isset($config->run_only->start_with_run_num)) {
		$start = $config->run_only->start_with_run_num;
	}
	foreach (range($start, $test->repetitions) as $run_num) {
		check_pool_is_empty();
        echo get_date() . "Test #" . ($num_test++) . ": $test->name - Run $run_num/$test->repetitions ...\n";
        echo get_date() . "  [";
        $ok = call_user_func($test->code, $run_num);
        echo "]\n";
		print_result($ok);
		wait(0, 0, TRUE);
		check_pool_is_empty();
	}
}

// Functions

function logd($what) {
    if (DEBUG) {
        echo get_date() . "$what\n";
        return TRUE;
    }
    return FALSE;
}

function _assert($file, $expected_content) {
    $content = @file_get_contents($file);
    // This ls is somehow needed to allow PHP to refresh it's file cache. Without it, some tests will sometimes fail...
    global $config;
    if (DEBUG) {
        echo "*** " . get_date() . "\n";
        $folders = [
            "/mnt/hdd*/gh/$config->share_name/",
            "/mnt/hdd*/gh/" . Metastores::METASTORE_DIR . "/$config->share_name/",
            "$config->share_dir/",
            "/mnt/hdd*/gh/$config->share_name/*/",
            "/mnt/hdd*/gh/" . Metastores::METASTORE_DIR . "/$config->share_name/*/",
            "$config->share_dir/*/",
        ];
        passthru('find ' . implode(' ', array_map('escapeshellarg', $folders)) . ' -type f 2>/dev/null');
        echo "***\n";
    }
    if ($expected_content != $content) {
        $file_exists = file_exists($file);
        die(get_date() . "assert failed: $file should contain '$expected_content'; it contains '$content' (file exists? " . ($file_exists ? 'yes' : 'no') . ").\n");
    }
}

function get_date() {
    return date("M ") . sprintf('%2d', (int) date("d")) . date(" H:i:s ");
}

function print_result($ok) {
	echo get_date() . "  Result: " . ($ok ? 'OK' : 'FAILED') . "\n";
	if (!$ok) {
		die("\n");
	}
}

function wait($wait_num = 0, $i = 0, $quiet = FALSE) {
	global $config;
	if (@$config->dont_wait) {
		return;
	}
	$wait = $wait_num == 0 || ($i-1)/(pow(2, $wait_num-1)) % 2 == 1;
	if ($wait) {
        $quiet || print('W');
		$last_line_before = exec('tail -1 ' . escapeshellarg($config->log_file));
		while (TRUE) {
			$last_line = exec('tail -1 ' . escapeshellarg($config->log_file));
			if ($last_line_before != $last_line && strpos($last_line, '... Sleeping.') !== FALSE) {
				break;
			}
			usleep(1000*100); // 100ms
		}
	} else {
        $quiet || print('-');
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
