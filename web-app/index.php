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

$config_file = 'files/greyhole.conf-' . md5($_SERVER['REMOTE_ADDR']);
$smb_config_file = 'files/smb.conf-' . md5($_SERVER['REMOTE_ADDR']);

if (isset($_GET['done'])) {
	render_apply_changes_page();
	exit();
}

if (count($_GET) == 0) {
	copy('/etc/greyhole.conf', $config_file);
	copy('/etc/samba/smb.conf', $smb_config_file);
}

include('includes/common.php');
parse_config();

if (count($_GET) == 0) {
	$partitions = get_partitions();
	$shares = get_shares();
	save_config();
	header('Location: ' . $_SERVER['SCRIPT_NAME'] . '?edit=1');
	exit();
}

$base_url = 'http://' . $_SERVER['HTTP_HOST'] . '/';

$partitions = get_partitions();
$shares = get_shares();

if (isset($_GET['share'])) {
	if (isset($shares[$_GET['share']])) {
		$share = $shares[$_GET['share']];
	} else {
		die("Unknown share specified.");
	}
}

if (isset($_GET['part'])) {
	$found = FALSE;
	foreach ($partitions as $part) {
		if ($part->path == $_GET['part']) {
			$found = TRUE;
			break;
		}
	}
	if (!$found) {
		die("Unknown partition specified.");
	}
}

if (isset($_GET['a'])) {
	switch($_GET['a']) {
		case 'toggle':
			if ($share->num_copies > 0) {
				$share->num_copies = 0;
				$share->vfs = str_replace('greyhole', '', $share->vfs);
				if (empty($share->vfs)) {
					unset($share->vfs);
				}
				unset($share->dfree);
				change_smb_conf('remove', $share->name);
			} else {
				$share->num_copies = 1;
				if (!isset($share->vfs)) {
					$share->vfs = 'greyhole';
				} else {
					$share->vfs .= ' greyhole';
				}
				$share->dfree = '/usr/bin/greyhole-dfree';
				change_smb_conf('add', $share->name);
			}
			break;
		case 'toggle_replication':
			if ($share->num_copies > 1) {
				$share->num_copies = 1;
			} else {
				$share->num_copies = 2;
			}
			break;
		case 'update_num_copies':
			$share->num_copies = $_REQUEST['n'];
			break;
		case 'toggle_pool_part':
			$part->in_pool = !$part->in_pool;
			break;
	}
	save_config();
}

if (isset($_GET['share'])) {
	render_share_html($share);
	exit();
}

if (isset($_GET['part'])) {
	render_part_html($part);
	exit();
}

?>

<?php render_header('Greyhole Configuration') ?>

<div>Once you're done, apply your changes <a href="<?php echo $base_url ?>index.php?done=1">here</a>.</div>

<h1>Pooled Partitions</h1>
<table cellspacing="0" cellpadding="0">
	<thead>
	<tr>
		<td class="padded_cell">Pooled</td>
		<td class="padded_cell" style="width:16px">&nbsp;</td>
		<td class="padded_cell">Mount Point</td>
		<td class="padded_cell" align="center">Total Space</td>
		<td class="padded_cell" align="center">Free Space</td>
		<td class="padded_cell" align="center">Free Space (%)</td>
		<td class="padded_cell">&nbsp;</td>
	</tr>
	</thead>
	<tbody>
	<?php
	foreach ($partitions as $part) {
		$spacecolor = "cool";
		if ($part->bytes_free < ($part->bytes_total * 0.20)) {
			$spacecolor = "warm";
		}
		if ($part->bytes_free < ($part->bytes_total * 0.10)) {
			$spacecolor = "hot";
		}
		?>
		<tr>
			<td align="center" class="padded_cell<?php echo ($part->path == '/') ? ' root_partition' : '' ?>">
				<?php render_part_html($part) ?>
			</td>
			<td style="width:16px" class="padded_cell<?php echo ($part->path == '/') ? ' root_partition' : '' ?>">
				<img alt="Working" class="theme-image" id="spinner-part-<?php echo $part->id ?>" src="<?php echo $base_url ?>images/working.gif?<?php echo time() ?>" style="display: none;" title="Working">
			</td>
			<td class="padded_cell<?php echo ($part->path == '/') ? ' root_partition' : '' ?>">
				<?php echo $part->path ?>
			</td>
			<td align="center" class="padded_cell<?php echo ($part->path == '/') ? ' root_partition' : '' ?>">
				<?php echo bytes_to_human($part->bytes_total) ?>
			</td>
			<td align="center" class="padded_cell freespace temperature-<?php echo $spacecolor ?><?php echo ($part->path == '/') ? ' root_partition' : '' ?>">
				<?php echo bytes_to_human($part->bytes_free) ?>
			</td>
			<td align="center" class="padded_cell freespace temperature-<?php echo $spacecolor ?><?php echo ($part->path == '/') ? ' root_partition' : '' ?>">
				<?php echo number_format($part->bytes_free/$part->bytes_total*100, 1) ?>%
			</td>
			<?php if ($part->path == '/' && count($partitions) > 0): ?>
				<td rowspan="<?php echo count($partitions) ?>" style="max-width:500px" valign="top">
					<div class="root_partition padded_cell"><span class="i18n-root_fs_warning">Note: Including the root partition in your storage pool could cause your server to behave erratically, if you ever fill your pool to capacity. Use with caution.</span></div>
				</td>
			<?php endif; ?>
		</tr>
		<?php
	}
	?>
	</tbody>
</table>
<script type="text/javascript">
function reloadPage() {
	window.location.href = 'index.php?edit=1';
}
function showHideSharesOptions() {
	var i = 0;
	var partSelected = false;
	while (true) {
		if (!$('greyhole_part_'+i+'_enabled')) {
			break;
		}
		if (clickedEl == 'greyhole_part_'+i+'_enabled' && !$('greyhole_part_'+i+'_enabled').checked) {
			partSelected = true;
		} else if (clickedEl != 'greyhole_part_'+i+'_enabled' && $('greyhole_part_'+i+'_enabled').checked) {
			partSelected = true;
		}
		if (partSelected) {
			break;
		}
		i++;
	}
	if (partSelected) {
		$('no_shares_options').style.display = 'none';
		$('shares_options').style.display = '';
	} else {
		$('no_shares_options').style.display = '';
		$('shares_options').style.display = 'none';
	}
}
</script>

<br/><br/>
<h1>Shares</h1>

<div id="no_shares_options" style="display:<?php echo (count($storage_pool_directories) > 0 ? 'none' : '') ?>">
	You need to select at least one partition above before you can enable Greyhole for specific shares.
</div>
<div id="shares_options" style="display:<?php echo (count($storage_pool_directories) == 0 ? 'none' : '') ?>">
	<?php
	foreach ($shares as $share) {
		echo "<h2>" . htmlentities($share->name) . "</h2>";
		render_share_html($share);
		echo '<hr/>';
	}
	?>
	<div>Once you're done, apply your changes <a href="<?php echo $base_url ?>index.php?done=1">here</a>.</div>
</div>

<?php render_footer() ?>

<?php
function get_shares() {
	global $shares_options, $delete_moves_to_attic, $smb_config_file;
	$shares = array();
	$smb_conf = file_get_contents($smb_config_file);
	$id = 0;
	foreach (explode("\n", $smb_conf) as $line) {
		$line = trim($line);
		if (strlen($line) == 0) { continue; }
		if ($line[0] == '[' && preg_match('/\[([^\]]+)\]/', $line, $regs)) {
			if (isset($share_name) && isset($shares[$share_name])) {
				if (!isset($shares[$share_name]->path)) {
					// Previous share has no path!
					unset($shares[$share_name]);
					$id--;
				} else if (!isset($shares[$share_name]->vfs) || strpos($shares[$share_name]->vfs, 'greyhole') === FALSE) {
					// Share is configured in greyhole.conf, but not in smb.conf
					$shares[$share_name]->num_copies = 0;
				}
			}
			$share_name = $regs[1];
		}
		if (preg_match('/printable[ \t]*=[ \t]*yes/', $line) && isset($share_name)) {
			// Don't work with 'printable' shares
			unset($shares[$share_name]);
			unset($share_name);
			$id--;
		}
		if (!isset($share_name)) { continue; }
		if ($share_name == 'global') { continue; }
		if ($share_name == 'print$') { continue; }
		if ($line[0] == '[' && preg_match('/\[([^\]]+)\]/', $line, $regs)) {
			$shares[$share_name] = (object) array('id' => $id++, 'name' => $share_name, 'num_copies' => 0);
			if (isset($shares_options[$share_name])) {
				$shares[$share_name]->num_copies = $shares_options[$share_name]['num_copies'];
				if (isset($shares_options[$share_name]['delete_moves_to_attic'])) {
					$shares[$share_name]->delete_moves_to_attic = $shares_options[$share_name]['delete_moves_to_attic'];
				} else {
					$shares[$share_name]->delete_moves_to_attic = $delete_moves_to_attic;
				}
			}
		}
		if (preg_match('/path[ \t]*=[ \t]*(.+)$/', $line, $regs)) {
			$shares[$share_name]->path = $regs[1];
		}
		if (preg_match('/vfs objects[ \t]*=[ \t]*(.+)$/', $line, $regs)) {
			$shares[$share_name]->vfs = $regs[1];
		}
		if (preg_match('/dfree command[ \t]*=[ \t]*(.+)$/', $line, $regs)) {
			$shares[$share_name]->dfree = $regs[1];
		}
	}
	return $shares;
}

function get_partitions() {
	global $storage_pool_directories;
	$partitions = array();
	$mtab = file_get_contents('/etc/mtab');
	$id = 0;
	foreach (explode("\n", $mtab) as $mount) {
		if (strpos($mount, '/dev') === FALSE) { continue; }
		$mount = explode(' ', $mount);
		$device = $mount[0];
		$path = $mount[1];
		if ($path == "/dev/pts") { continue; }
		if ($path == "/dev/shm") { continue; }
		if ($path == "/boot") { continue; }
		if ($path == "/tmp") { continue; }
		list($total, $free) = disk_stats($path);
		$in_pool = FALSE;
		if (is_array($storage_pool_directories)) {
			foreach ($storage_pool_directories as $pool_dir) {
				if ($pool_dir == str_replace('//', '/', "$path/gh")) {
					$in_pool = TRUE;
					break;
				}
			}
		}
		$partitions[] = (object) array('id' => $id++, 'path' => $path, 'bytes_total' => $total*1024, 'bytes_free' => $free*1024, 'in_pool' => $in_pool);
	}
	return $partitions;
}

function disk_stats($path) {
	$cmd = "df -k " . quoted_form($path) . " 2>&1 | grep -v \"^df: .*: No such file or directory$\" | tail -1 | awk '{print \$(NF-4),\$(NF-2)}'";
	exec($cmd, $responses);
	if (count($responses) > 0) {
		$response = explode(' ', $responses[0]);
		$total = $response[0];
		$free = $response[1];
	}
	return array($total, $free);
}

function render_share_html($share) {
	global $base_url, $storage_pool_directories;
	if (isset($_GET['callback'])) {
		$callback = $_GET['callback'];
	} else {
		$callback = 'showHideSharesOptions';
	}
	$id = 'greyhole_' . $share->id;
	?>
	<span id="<?php echo $id ?>">
		<input type="checkbox" 
			id="greyhole_<?php echo $share->id ?>_enabled" 
			<?php echo ($share->num_copies > 0 ? 'checked="checked"' : '') ?>
			onclick="clickedEl=this.id;Element.show('spinner-greyhole-<?php echo $share->id ?>'); new Ajax.Updater('<?php echo $id ?>', '<?php echo $base_url ?>index.php?share=<?php echo urlencode($share->name) ?>&amp;a=toggle', {asynchronous:true, evalScripts:true, onSuccess:function(request){Element.hide('spinner-greyhole-<?php echo $share->id ?>');<?php echo $callback . '();' ?>}, parameters:Form.serialize('<?php echo $id ?>')}); return false;"/>
		<span class="i18n-greyhole_enabled">Share Managed by Greyhole</span>
		<img alt="Working" class="theme-image" id="spinner-greyhole-<?php echo $share->id ?>" src="<?php echo $base_url ?>images/working.gif?<?php echo time() ?>" style="display: none;" title="Working">

	<?php if ($share->num_copies > 0 && count($storage_pool_directories) > 1): ?>
		<br/>
		<input type="checkbox" 
			id="greyhole_repl_<?php echo $share->id ?>_enabled" 
			<?php echo ($share->num_copies > 1 ? 'checked="checked"' : '') ?>
			onclick="clickedEl=this.id;Element.show('spinner-greyhole-repl-<?php echo $share->id ?>'); new Ajax.Updater('<?php echo $id ?>', '<?php echo $base_url ?>index.php?share=<?php echo urlencode($share->name) ?>&amp;a=toggle_replication', {asynchronous:true, evalScripts:true, onSuccess:function(request){Element.hide('spinner-greyhole-repl-<?php echo $share->id ?>');<?php echo $callback . '();' ?>}, parameters:Form.serialize('<?php echo $id ?>')}); return false;"/>
		<span class="i18n-greyhole_replication">Replication Enabled</span>
		<img alt="Working" class="theme-image" id="spinner-greyhole-repl-<?php echo $share->id ?>" src="<?php echo $base_url ?>images/working.gif?<?php echo time() ?>" style="display: none;" title="Working">
	<?php endif; ?>

	<?php if ($share->num_copies > 1 && count($storage_pool_directories) > 1): ?>
		<br/>
		<span class="i18n-greyhole_num_copies">Number of file copies to maintain</span>:
		
		<select id="greyhole_num_copies_<?php echo $share->id ?>"
			name="greyhole_num_copies"
			onchange="clickedEl=this.id;Element.show('spinner-greyhole-numcopies-<?php echo $share->id ?>'); new Ajax.Updater('<?php echo $id ?>', '<?php echo $base_url ?>index.php?share=<?php echo urlencode($share->name) ?>&amp;a=update_num_copies', {asynchronous:true, evalScripts:true, onSuccess:function(request){Element.hide('spinner-greyhole-numcopies-<?php echo $share->id ?>');<?php echo $callback . '();' ?>}, parameters:'n=' + this.value})">
			<?php
			for ($i=2; $i<=count($storage_pool_directories); $i++) {
				?><option value="<?php echo $i ?>"<?php echo ($share->num_copies == $i ? ' selected="selected"' : '') ?>><?php echo $i ?></option><?php
			}
			?>
			<option value="100"<?php echo ($share->num_copies == 100 ? ' selected="selected"' : '') ?>>As many as possible</option>
		</select>
		<img alt="Working" class="theme-image" id="spinner-greyhole-numcopies-<?php echo $share->id ?>" src="<?php echo $base_url ?>images/working.gif?<?php echo time() ?>" style="display: none;" title="Working">
	<?php endif; ?>
	</span>
	<?php
}

function render_part_html($part) {
	global $base_url;
	if (isset($_GET['callback'])) {
		$callback = $_GET['callback'];
	} else {
		$callback = 'reloadPage';
	}
	$id = 'greyhole_part_' . $part->id;
	?>
	<span id="<?php echo $id ?>">
		<input type="checkbox" 
			id="greyhole_part_<?php echo $part->id ?>_enabled" 
			<?php echo ($part->in_pool ? 'checked="checked"' : '') ?>
			onclick="clickedEl=this.id;Element.show('spinner-part-<?php echo $part->id ?>'); new Ajax.Updater('<?php echo $id ?>', '<?php echo $base_url ?>index.php?part=<?php echo urlencode($part->path) ?>&amp;a=toggle_pool_part', {asynchronous:true, evalScripts:true, onSuccess:function(request){Element.hide('spinner-part-<?php echo $part->id ?>');<?php echo $callback . '();' ?>}, parameters:Form.serialize('<?php echo $id ?>')}); return false;"/>
	</span>
	<?php
}

function render_apply_changes_page() {
	$dir = str_replace('index.php', 'files', $_SERVER['SCRIPT_FILENAME']);
	render_header('Greyhole Configuration - Apply your changes');
	?>
	<span style="color:red">Your changes have not yet been applied.</span><br/>
	You'll need to execute the following command in a terminal or using SSH, logged as <em>root</em> on your server:
	<pre>/usr/bin/greyhole-config-update '<?php echo $dir ?>' <?php echo md5($_SERVER['REMOTE_ADDR']) ?></pre>
	<?php
	render_footer();
}

function render_header($title) {
	global $base_url;
	?>
	<html>
	<head>
		<title><?php echo htmlentities($title) ?></title>
		<script type="text/javascript" src="<?php echo $base_url ?>javascript/prototype.js"></script>
		<style type="text/css">
			.freespace {
				font-weight: bold;
			}
			.temperature-cool {
				color: green;
			}
			.temperature-warm {
				color: orange;
			}
			.temperature-hot {
				color: red;
			}
			.padded_cell {
				padding: 5px;
			}
			.root_partition {
				background-color: #FFFFE0;
			}
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

function save_config() {
	global $config_file;
	$config_file_content = explode("\n", file_get_contents($config_file));

	$config_file_template = '';

	$found_shares_options = FALSE;
	$found_pool_options = FALSE;
	foreach ($config_file_content as $line) {
		if (strpos(trim($line), 'num_copies[') === 0 || strpos(trim($line), '#num_copies[') === 0) {
			if (!$found_shares_options) {
				$found_shares_options = TRUE;
				$config_file_template .= '$shares_options' . "\n";
			}
			continue;
		}
		if (strpos(trim($line), 'storage_pool_directory') === 0 || strpos(trim($line), '#storage_pool_directory') === 0) {
			if (!$found_pool_options) {
				$found_pool_options = TRUE;
				$config_file_template .= '$pool_options' . "\n";
			}
			continue;
		}
		$config_file_template .= $line . "\n";
	}

	$config_file_template = str_replace('$shares_options', get_shares_options(), $config_file_template);
	$config_file_template = str_replace('$pool_options', get_pool_options(), $config_file_template);
	
	file_put_contents($config_file, trim($config_file_template)."\n");
}

function get_shares_options() {
	global $shares;
	$shares_options = '';
	foreach ($shares as $share) {
		if ($share->num_copies > 0) {
			$shares_options .= "\t" . 'num_copies[' . $share->name . '] = ' . $share->num_copies . "\n";
		}
	}
	if (trim($shares_options) == '') {
		$shares_options = '#num_copies[ShareName] = 0';
	}
	return "\t" . trim($shares_options);
}

function get_pool_options() {
	global $partitions;
	$pool_options = '';
	$total_space = 0;
	$num_parts_in_pool = 0;
	foreach ($partitions as $part) {
		if (!$part->in_pool) { continue; }
		$total_space += $part->bytes_total;
		$num_parts_in_pool++;
	}
	if ($num_parts_in_pool == 0) {
		return "\t#storage_pool_directory = /something/gh, min_free = 10gb";
	}
	$warning_free_space = $total_space * 0.01;
	$warning_free_space_per_part = $warning_free_space / $num_parts_in_pool;
	$warning_free_space_per_part = ceil($warning_free_space_per_part/1024/1024/1024);
	foreach ($partitions as $part) {
		if (!$part->in_pool) { continue; }
		$greyhole_path = str_replace('//', '/' , $part->path . '/gh');
		$pool_options .= "\t" . 'storage_pool_directory = ' . $greyhole_path . ', min_free: ' . $warning_free_space_per_part .'gb' . "\n";
	}
	if (trim($pool_options) == '') {
		$pool_options = '#storage_pool_directory = /something/gh, min_free = 10gb';
	}
	return "\t" . trim($pool_options);
}

function change_smb_conf($action, $share) {
	global $smb_config_file;
	$config_file_content = explode("\n", file_get_contents($smb_config_file));

	$config_file_template = '';
	$found_vfs = FALSE;
	$buffer = '';
	foreach ($config_file_content as $line) {
		if (substr(trim($line), 0, 1) == '[' && preg_match('/\[([^\]]+)\]/', trim($line), $regs)) {
			if (isset($share_name) && $share_name == $share) {
				// Previous share is $share_name
				$config_file_template .= add_remove_gh_options($action, $found_vfs) . $buffer;
				$buffer = '';
			}
			$share_name = $regs[1];
		}
		if (isset($share_name) && $share_name == $share) {
			if (strpos(trim($line), 'dfree command') === 0) {
				continue;
			}
			if (strpos(trim($line), 'vfs objects') === 0) {
				if (preg_match('/vfs objects[ \t]*=[ \t]*(.+)$/', $line, $regs)) {
					$found_vfs = $regs[1];
				}
				continue;
			}
		}

		if (trim($line) == '') {
			$buffer .= $line . "\n";
			continue;
		}
		$config_file_template .= $buffer . $line . "\n";
		$buffer = '';
	}
	if (isset($share_name) && $share_name == $share) {
		$config_file_template .= add_remove_gh_options($action, $found_vfs) . $buffer;
	}

	file_put_contents($smb_config_file, trim($config_file_template)."\n");
}

function add_remove_gh_options($action, $found_vfs) {
	$config_file_template = '';
	if ($action == 'add') {
		if ($found_vfs === FALSE) {
			$config_file_template .= "\tvfs objects = greyhole\n";
		} else {
			$config_file_template .= "\tvfs objects = $found_vfs greyhole\n";
		}
		$config_file_template .= "\tdfree command = /usr/bin/greyhole-dfree\n";
	} else if ($action == 'remove') {
		if ($found_vfs !== FALSE) {
			$found_vfs = trim(str_replace('greyhole', '', $found_vfs));
			if (strlen($found_vfs) > 0) {
				$config_file_template = "\tvfs objects = $found_vfs\n";
			}
		}
	}
	return $config_file_template;
}
