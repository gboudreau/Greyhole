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

$data_file = '/usr/local/greyhole/gh-disk-usage.log';

if (!file_exists($data_file)) {
	render_header('Greyhole Disk Usage');

	?>
	<h1>Greyhole Storage Pool Disk Usage - All Shares</h1>
	<div>Greyhole disk usage stats are computed during the nightly fsck run.</div>
	<div>Those stats have not yet been computed.</div>
	<div>Please wait for fsck to run once.</div>
	<?php

	render_footer();
	exit(0);
}

$base_url = 'http://' . $_SERVER['HTTP_HOST'] . '/';

require_once('includes/common.php');

exec("tail -2 $data_file | grep '^# '", $results);
$shares_options = unserialize(substr($results[0], 2));
unset($results);

if (!isset($_GET['d'])) {
	$root_dir = '/';
	$start_level = 1;
} else {
	$root_dir = $_GET['d'];
	$chars_count = count_chars($root_dir, 1);
	$start_level = $chars_count[ord('/')] + 1;
}

$table_data = array();
$legend = array();
for ($i = 0; $i < 2; $i++) {
	$level = $start_level + $i;

	exec("grep '^$level $root_dir' $data_file", $results);
	$sizes = array();
	foreach ($results as $result) {
		$path = substr($result, 2, strrpos($result, ' ')-2);
		if ($level == $start_level) {
			$path .= '/';
		}
		
		$share = substr($path, 1, strpos(substr($path, 1), '/'));
		$num_copies = $shares_options[$share]['num_copies'];
		
		$sizes[$path] = (int) substr($result, strrpos($result, ' ')) * $num_copies;
	}
	unset($results);

	if ($level > $start_level) {
		foreach ($sizes as $path => $size) {
			if (100*$size/$total_size < 1) {
				$parent_path = substr($path, 0, strrpos($path, '/'));
				@$sizes["$parent_path/.../"] += $size;
				unset($sizes[$path]);
			}
		}
	}
	ksort($sizes);

	// Add the $sizes for files...
	if ($level == $start_level) {
		// ... for the root_dir
		if ($start_level > 1) {
			$share = substr($path, 1, strpos(substr($path, 1), '/'));
			$num_copies = $shares_options[$share]['num_copies'];

			exec("grep '^". ($start_level-1) ." $root_dir' $data_file", $results);
			$results = explode(' ', $results[0]);
			$files_size = (int) $results[count($results)-1] * $num_copies;
			unset($results);

			foreach ($sizes as $size) {
				$files_size -= $size;
			}
			$sizes["$root_dir/*"] = $files_size;
		}
	} else {
		// ... and for the 2nd level
		foreach ($previous_sizes as $previous_path => $previous_size) {
			$files_size = $previous_size;
			foreach ($sizes as $path => $size) {
				if (strpos($path, $previous_path) === 0) {
					$files_size -= $size;
				}
			}
			$sizes["$previous_path*"] = $files_size;
		}
	}
	ksort($sizes);
	$total_size = array_sum($sizes);

	if ($level > $start_level) {
		// Keep $sizes sorted to match the previous level, but sort them by size desc otherwise.
		$sorted_sizes = array();
		list($path, $size) = array_kshift($sizes);
		foreach ($previous_sizes as $previous_path => $previous_size) {
			$to_sort = array();
			while (strstr($path, $previous_path) == $path) {
				$to_sort[$path] = $size;
				if (count($sizes) == 0) {
					break;
				}
				list($path, $size) = array_kshift($sizes);
			}
			arsort($to_sort);
			unset($final);
			foreach ($to_sort as $p => $s) {
				if (strpos($p, '/.../') == strlen($p)-5) {
					$final = array($p, $s);
				} else {
					$sorted_sizes[$p] = $s;
				}
			}
			if (isset($final)) {
				list($p, $s) = $final;
				$sorted_sizes[$p] = $s;
			}
		}
		$sizes = $sorted_sizes;
	}

	$table_data[$i] = array();
	foreach ($sizes as $path => $size) {
		$table_data[$i][] = number_format(100*$size/$total_size, 10);
		if ($level == $start_level) {
			$legend[] = urlencode(get_legend($path, $size));
		}
	}
	$table_data[$i] = implode(',', $table_data[$i]);

	if ($level == $start_level) {
		$previous_sizes = $sizes;
	} else {
		$colors = colorize($previous_sizes, $sizes);
	}
}

// Add a legend to the legend! :D
array_kunshift($previous_sizes, 'Legend', 0.0000000001);
$table_data[0] = $previous_sizes['Legend'] .','. $table_data[0];
array_unshift($colors[0], 'FFFFFF');
array_unshift($legend, urlencode('Directory (Files size x # copies = Disk Used)'));

// Add a total to the legend!
$previous_sizes['Total'] =  0.0000000001;
$table_data[0] = $table_data[0] .','. $previous_sizes['Total'];
array_push($colors[0], 'FFFFFF');
array_push($legend, urlencode(get_legend('Total Disk Used', array_sum($previous_sizes))));

$img_url = "http://chart.apis.google.com/chart?cht=pc&chd=t:$table_data[0]|$table_data[1]&chs=720x388&chco=". implode('|', $colors[0]) .','. implode('|', $colors[1]) .'&chdl='. implode('|', $legend);
?>

<?php render_header('Greyhole Disk Usage') ?>

<h1>Greyhole Storage Pool Disk Usage
	<?php if ($root_dir == '/'): ?>
		- All Shares
	<?php else: ?>
		- <?php echo substr($root_dir, 1) ?>
	<?php endif; ?>
</h1>
<?php if ($start_level > 1): ?>
	[<a href="javascript:window.history.go(-1)">back</a>]<br/>
<?php endif; ?>

<?php echo get_map_html($img_url) ?>
<img id="graph" src="<?php echo $img_url ?>" usemap="#disk_usage_map" />

<?php render_footer() ?>

<?php
function render_header($title) {
	global $base_url;
	?>
	<html>
	<head>
		<title><?php echo htmlentities($title) ?></title>
		<script type="text/javascript" src="<?php echo $base_url ?>javascript/prototype.js"></script>
		<style type="text/css">
		</style>
	</head>
	<body>
	<?php
}

function render_footer() {
	?>
	</body>
	</html>
	<?php
}

// Colorize slices, except ... ones
function colorize($previous_sizes, $sizes) {
	$colors = array();
	$colors[0] = colorize_serie($previous_sizes);
	$colors[1] = colorize_serie($sizes);
	return $colors;
}

function colorize_serie($sizes) {
	$color_min = array(hexdec('66'), hexdec('00'), hexdec('00'));
	$color_max = array(hexdec('FF'), hexdec('BB'), hexdec('00'));
	$color_range = $color_max[0]-$color_min[0] + $color_max[1]-$color_min[1] + $color_max[2]-$color_min[2];

	$colors = array();

	$num_no_colors = 0;
	foreach ($sizes as $path => $size) {
		if (strpos($path, '/.../') == strlen($path)-5) {
			$num_no_colors++;
		}
	}

	$color_step = round($color_range / (count($sizes)-1-$num_no_colors));
	$current_color = $color_min;
	foreach ($sizes as $path => $size) {
		if (strpos($path, '/.../') == strlen($path)-5) {
			$colors[] = 'CCCCCC';
		} else if (strpos($path, '/**') == strlen($path)-3) {
				$colors[] = 'FFFFFF';
		} else {
			$colors[] = dechex(256*256*$current_color[0]+256*$current_color[1]+$current_color[2]);
			if ($current_color[0] < $color_max[0]) {
				$current_color[0] += $color_step;
				if ($current_color[0] > 255) { $current_color[0] = 255; }
			} else if ($current_color[1] < $color_max[1]) {
				$current_color[1] += $color_step;
				if ($current_color[1] > 255) { $current_color[1] = 255; }
			} else if ($current_color[2] < $color_max[2]) {
				$current_color[2] += $color_step;
				if ($current_color[2] > 255) { $current_color[2] = 255; }
			}
		}
	}
	
	return $colors;
}

function get_map_html($img_url) {
	global $previous_sizes, $sizes;

	require_once('PEAR.php');
	pear::loadExtension('json');

	$json = exec('curl "'. $img_url .'&chof=json"');
	$map = json_decode($json)->chartshape;

	$map_html = "<map name=\"disk_usage_map\">\n";
	foreach ($map as $area) {
		if (count($previous_sizes) > 0) {
			list($path, $size) = array_kshift($previous_sizes);
		} else {
			list($path, $size) = array_kshift($sizes);
		}
		$href_dir = $path;
		if (strrpos($path, '/.../') == strlen($path)-5) {
			$href_dir = substr($path, 0, strlen($path)-5);
		} else if (strrpos($path, '/*') == strlen($path)-2) {
			$href_dir = substr($path, 0, strlen($path)-2);
		} else if (strrpos($path, '/') == strlen($path)-1) {
			$href_dir = substr($path, 0, strlen($path)-1);
		}
		if (strrpos($path, '/**') != strlen($path)-3 && $href_dir != 'Legend') {
			$map_html .= "  <area name=\"$area->name\" shape=\"$area->type\" coords=\"". implode(',', $area->coords) ."\" href=\"du.php?d=". urlencode($href_dir) ."\"  title=\"". htmlentities(get_legend($path, $size, TRUE), ENT_COMPAT, 'UTF-8') ."\">\n";
		}
	}
	$map_html .= "</map>\n";

	return $map_html;
}

function get_legend($path, $size, $simplified=FALSE) {
	global $shares_options;
	$share = substr($path, 1, strpos(substr($path, 1), '/'));
	$num_copies = $shares_options[$share]['num_copies'];
	if (!$simplified) {
		if (strpos($path, '/*') == strlen($path)-2) {
			$path .= " (files)";
		}
	}
	if ($simplified || $num_copies == null) {
		return "$path (". bytes_to_human($size, FALSE) .")";
	} else {
		return "$path (". bytes_to_human($size/$num_copies, FALSE) ." x $num_copies = ". bytes_to_human($size, FALSE) .")";
	}
}

function array_kshift(&$arr) {
  list($k) = array_keys($arr);
  $r  = $arr[$k];
  unset($arr[$k]);
  return array($k, $r);
}


function array_kunshift(&$arr, $key, $value) {
  $arr = array_reverse($arr);
  $arr[$key] = $value;
  $arr = array_reverse($arr);
}
