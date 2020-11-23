<?php
//header('Content-Type: text/html; charset=utf-8');
set_time_limit(90);
/*
   Notes 2020-11-08:
          Updated to be compatible with the 'new' greyhole web-ui, and clean up some code.
   Old code remains commented at this time, pending future removal. The rewrite for the Greyhole
   WebUI likely breaks compability with apache/suPHP, due to internal variables fetched from
   the greyhole WebUI PHP server. The share path is now detected automatically, rather than
   hard coded. Upon file restoration,  orphan-scanning fsck is scheduled with the Greyhole daemon,
   to properly update the share.

   TODOs:
    * Better theme integratation with WebUI UX.
    * Possibly fix file viewer/downloader (header issues)
    * Fix any outstanding bugs
*/

$qpath = $_SERVER['SCRIPT_NAME'];
parse_str($_SERVER['QUERY_STRING'],$query);

// build query data for links and forms
$query['page'] = "id_".md5($name)."_tab"; // overwrite page ID with our own
$cururi = "?page=".$query['page'];

$drives = getDrives();

// We could use _SERVER['GET'] but we already use $query
// for ['page'] and this works fine
$ext = isset($query['path']) ? $query['path'] : "";
$view = isset($query['view']) ? $query['view'] : "";
$drive = isset($query['drive']) ? $query['drive'] : "";
$action = isset($query['action']) ? $query['action'] : "";
$confirm = isset($query['confirm']) ? $query['confirm'] : "";

if (($drive == "" || $view == "") && $action == "") {
	$ptitle = $name;
	if ($ext != "") {
		$ptitle .= " - ".$ext;
	}

	echo "<html>\n";
	echo "<head>\n";
	echo "<title>".$ptitle."</title>\n";
	echo "<style type=\"text/css\">\n";
	echo "td, th {\n";
	echo "\tpadding: 7px 5px 7px 5px;\n";
	echo "\tborder: 1px dotted #000;\n";
	echo "}\n";
	echo "th {\n";
	echo "\ttext-align: left;\n";
	echo "\tfont-weight: bold;\n";
	echo "\tfont-style: italic;\n";
	echo "}\n";
	echo "form {\n";
	echo "\tdisplay: inline;\n";
	echo "}\n";
	echo ".ral {\n";
	echo "\ttext-align: right\n";
	echo "}\n";
	echo "</style>\n";
	echo "</head>\n";
	echo "<body>\n";
	echo "<h2 class=\"mt-8\">".$ptitle."</h2>\n";
	echo "<table id=\"table-trashman\">\n";
	echo "<thead>\n";
	echo "<tr>\n";
	echo "<th>Path</th>\n";
	echo "<th>Size</th>\n";
	echo "<th>Modified</th>\n";
	echo "<th>Copies</th>\n";
	echo "<th>Actions</th>\n";
	echo "</tr>\n";
	echo "</thead>\n";
	echo "<tbody>\n";

	if ($ext != "") {
		$parentpath = rtrim(dirname($ext,1),"/.");
		echo "<tr>\n";
		echo "<td><a href=\"".$cururi."&path=".rawurlencode($parentpath)."\">Parent Directory</a></td>\n";
		for ($i=0; $i<4; $i++) echo "<td> - </td>\n";
		echo "</tr>\n";
	}

	foreach ($drives as $d) {
		$path = $d."/.gh_trash/".$ext;
		if ($h = @opendir($path)) {
		    while (false !== ($e = readdir($h))) {
			if ($e != "." && $e != "..") {
				$list[] = array($e,$d);
			}
		    }
		closedir($h);
		}
	}

	if (@count($list) > 0) {
		foreach ($list as $l) {
			if (@in_array($l[0],@$r)) {
				$q[$l[0]] = (@$q[$l[0]] + 1);
			} else {
				$r[] = $l[0];
				// First drive is fine
				if (!isset($z[$l[0]])) {
					$z[$l[0]] = $l[1];
					}
			}
		}
	}

	if (@count($r) > 0) {
		foreach ($r as $k) {
			echo "<tr>\n";
			if (is_dir($z[$k]."/.gh_trash/".$ext."/".$k)) {
				echo "<td><a href=\"".$cururi."&path=".rawurlencode($ext."/".$k)."\">".$k."</a></td>\n";
				for ($i=0; $i<2; $i++) echo "<td> - </td>\n";
				echo "<td>".(@$q[$k]+1)."</td>\n";
			} else {
				$dr = (array_search($z[$k],$drives)+1);
				// View (download) feature currently unimplemented due to being unable to override headers
//				echo "<td><a href=\"".$cururi."&view=".rawurlencode($ext."/".$k)."&drive=".$dr."\">".$k."</a></td>\n";
				echo "<td>".$k."</td>\n";
				$fsize = filesize($z[$k]."/.gh_trash/".$ext."/".$k);
				echo "<td>".$fsize." bytes (".formatBytes($fsize).")</td>\n";
				$ftime = filemtime($z[$k]."/.gh_trash/".$ext."/".$k);
				echo "<td>".strftime("%m/%e/%Y %r",$ftime)."</td>\n";
				echo "<td>".(@$q[$k]+1)."</td>\n";
			}
			echo "<td>\n";

			// Delete Button
			echo "<form method=\"GET\">\n";
			if (isset($query['page'])) {
				echo "<input type=\"hidden\" name=\"page\" value=\"".$query['page']."\">\n";
			}
			echo "<input type=\"hidden\" name=\"path\" value=\"".$ext."/".$k."\" />\n";
			echo "<input type=\"hidden\" name=\"action\" value=\"remove\" />\n";
			echo "<input type=\"submit\" value=\"Delete Permanently\" /></form>\n";

			// Restore Button
			echo "<form method=\"GET\">\n";
			if (isset($query['page'])) {
				echo "<input type=\"hidden\" name=\"page\" value=\"".$query['page']."\">\n";
			}
			echo "<input type=\"hidden\" name=\"path\" value=\"".$ext."/".$k."\" />\n";
			echo "<input type=\"hidden\" name=\"action\" value=\"restore\" />\n";
			echo "<input type=\"submit\" value=\"Restore\" /></form>\n";
			echo "</td>\n";
			echo "</tr>";
		}
	}
	echo "<tr>\n";
	echo "<td colspan=\"5\" class=\"ral\">\n";
	$space = getSpace(true);
	echo "<strong>Disk Space Status:</strong><br />\n";
	echo "<table class=\"ral\" width=\"100%\">\n";
	echo "<tr><td>Total:</td><td>".$space['total']."</td></tr>\n";
	echo "<tr><td>Used:</td><td>".$space['used']."</td></tr>\n";
	echo "<tr><td>Available:</td><td>".$space['avail']."</td></tr>\n";
	echo "<tr><td>Trash:</td><td>".$space['trash']."</td></tr>\n";
	echo "<tr><td>Possible:</td><td>".$space['possible']."</td></tr>\n";
	echo "</tbody>\n";
	echo "</table>\n";
	echo "</table>\n";
	echo "</body>\n";
	echo "</html>\n";
} else {
/*
	// view/download feature disabled
	if ($view != "" && $drive != "") {
		$dpath = $drives[($drive-1)]."/.gh_trash";
		$fpath = $dpath.$view;
		$meh = preg_split("/\//",$fpath);
		$file = $meh[(count($meh)-1)];
		$mime = mime_content_type($fpath);
		http_send_content_disposition($file,true);
		http_send_content_type($mime);
		http_send_file($fpath);
		exit();
	}
*/
	if ($action == "remove" && $ext != "") {
		if ($confirm != "Yes") {
			echo "<h2 class=\"mt-8\">Removing ".$ext."</h2>\n";
			if (is_dir($ext)) {
				echo "Are you sure you want to recursively destroy all copies of this directory, and the files within it?<br />\n";
			} else {
				echo "Are you sure you want to destroy all copies of this file in the trash?<br />\n";
			}
			echo "This action is not reversable!<br />\n";

			// Confirm Yes Button
			echo "<form method=\"GET\" style=\"display: inline\">\n";
			if (isset($query['page'])) {
				echo "<input type=\"hidden\" name=\"page\" value=\"".$query['page']."\">\n";
			}
			echo "<input type=\"hidden\" name=\"path\" value=\"".$ext."\" />\n";
			echo "<input type=\"hidden\" name=\"action\" value=\"remove\" />\n";
			echo "<input type=\"submit\" name=\"confirm\" value=\"Yes\" />\n";
			echo "</form>\n";

			// No Button
			echo "<form method=\"GET\" style=\"display: inline\">\n";
			if (isset($query['page'])) {
				echo "<input type=\"hidden\" name=\"page\" value=\"".$query['page']."\">\n";
			}

			$parentpath = rtrim(dirname($ext),"/.");

			echo "<input type=\"hidden\" name=\"path\" value=\"".$parentpath."\" />\n";
			echo "<input type=\"submit\" value=\"No\" />\n";
			echo "</form>\n";
		} else {
			foreach ($drives as $driv) {
                                $file = $driv."/.gh_trash".$ext;
                                if (is_dir($file)) {
                                        rrmdir($file);
                                        $removed[] = $file;
                                } else {
                                        if (file_exists($file)) {
                                                unlink($file);
                                                $removed[] = $file;
                                        }
                                }
			}
			if (count(@$removed) > 0) {
				echo "<h2 class=\"mt-8\">Removed ".$ext."</h2>\n";
				echo "The file has been removed from all Greyhole drives.<br />\n";
				echo "<br />\n";
				echo "Removed from:<br />\n";
				foreach ($removed as $r) {
					echo $r."<br />\n";
				}
			} else {
				echo "No such file. Perhaps it was already removed, or you refreshed the page.<br />";
			}
			echo "<br />\n";
			$parentpath = rtrim(dirname($ext),"/.");
			echo "<a href=\"".$cururi."&path=".$parentpath."\">Back to directory</a>";
		}
	}
	if ($action == "restore" && $ext != "") {
		$sh_info = getShareInfo($ext);
		$dest = $sh_info['landing_path'];
		$zerr = 0;

		if (!file_exists($dest)) {
			// only prompt for confirmation if the file exists already
			$confirm = "Yes";
		}

		if ($confirm != "Yes") {
			echo "<h2 class=\"mt-8\">Restoring ".$ext."</h2>\n";
			if (is_dir($ext)) {
				echo "A directory already exists in the share with this name. Any files within with the same name will be overwritten with the copy from the trash.<br />Would you like to continue?<br />\n";
			} else {
				echo "A file already exists in the share with this name. It will be overwritten with the copy from the trash.<br />Would you like to continue?<br />\n";
			}
			echo "This action is not reversable!<br />\n";

			// Confirm Yes Button
			echo "<form method=\"GET\" style=\"display: inline\">\n";
			if (isset($query['page'])) {
				echo "<input type=\"hidden\" name=\"page\" value=\"".$query['page']."\">\n";
			}
			echo "<input type=\"hidden\" name=\"path\" value=\"".$ext."\" />\n";
			echo "<input type=\"hidden\" name=\"action\" value=\"restore\" />\n";
			echo "<input type=\"submit\" name=\"confirm\" value=\"Yes\" />\n";
			echo "</form>\n";

			// No Button
			echo "<form method=\"GET\" style=\"display: inline\">\n";
			if (isset($query['page'])) {
				echo "<input type=\"hidden\" name=\"page\" value=\"".$query['page']."\">\n";
			}

			$parentpath = rtrim(dirname($ext),"/.");
			echo "<input type=\"hidden\" name=\"path\" value=\"".$parentpath."\" />\n";
			echo "<input type=\"submit\" value=\"No\" />\n";
			echo "</form>\n";

		} else {
			foreach ($drives as $driv) {
				$file = $driv."/.gh_trash".$ext;
				if (file_exists($file)) {
					$sources[] = $file;
				}
			}

			$parentpath = rtrim(dirname($ext),"/.");

			// First one is fine
			$source = @$sources[0];

			// Unless its an entire folder...
			if (is_dir($source)) {
				foreach ($sources as $s) {
					xcopy($s,$dest);
				}
			} elseif (file_exists($source)) {
				if (!is_dir(dirname($dest))) {
					mkdir(dirname($dest),0770,true);
				}
				copy($source,$dest);
			} else {
				echo "No such file. If you restored the file, it has been purged from the trash. Did you refresh the page?<br />";
				$zerr = 1;
			}

			if ($zerr === 0) {
				// Delete all copies from trash after restoring
				if (file_exists($dest)) {
					foreach ($sources as $s) {
						if (is_dir($s)) {
							rrmdir($s);
						}
						else {
							if (file_exists($s)) {
								unlink($s);
							}
						}
					}
				} else {
					echo "Internal error: failed to restore file. Trash untouched.\n";
					echo "<br />\n";
					echo "<a href=\"".$cururi."&path=".$parentpath."\">Back to directory</a>";
					exit();
				}
				echo "<h2 class=\"mt-8\">Restored ".$ext."</h2>\n";
				echo "The file has been restored to the share. It will no longer appear in the trash.<br />\n";
				echo greyholeFsck($sh_info['landing_path'])."<br />\n";
				echo "<br />\n";
			}
			echo "<a href=\"".$cururi."&path=".$parentpath."\">Back to directory</a>";
		}
	}
}

function rrmdir($dir) {
	// recursive remove directory
	if (is_dir($dir)) {
		$objects = scandir($dir);
		foreach ($objects as $object) {
			if ($object != "." && $object != "..") {
				if (filetype($dir."/".$object) == "dir") {
					rrmdir($dir."/".$object);
				} else {
					unlink($dir."/".$object);
				}
			}
		}
		reset($objects);
		rmdir($dir);
	}
}

function xcopy($src,$dest) {
	// recursive copy
	if (!is_dir($dest)) {
		mkdir($dest);
	}
	foreach (scandir($src) as $file) {
		if (!is_readable($src.'/'.$file)) continue;
		if (is_dir($src.'/'.$file)) {
			if (($file!='.') && ($file!='..')) {
				if(!is_dir($dest.'/'.$file)) {
					mkdir($dest . '/' . $file);
				}
				xcopy($src.'/'.$file, $dest.'/'.$file);
			}
		} else {
			if (!file_exists($dest.'/'.$file)) {
				copy($src.'/'.$file, $dest.'/'.$file);
			}
		}
	 }
}

function getSpace($formatBytes = false) {
//	$space['trash'] = 0;
//	$drives = getDrives();
//	foreach ($drives as $d) {
//		$dudat = `du -sk $d/.gh_trash/`;
//		$space['trash'] = ($space['trash'] + $dudat);
//	}
	global $stats;
	$space['total'] = $stats['Total']->total_space;
	$space['avail'] = $stats['Total']->free_space;
	$space['used']  = $stats['Total']->used_space;
	$space['trash'] = $stats['Total']->trash_size;
	$space['possible'] = ($space['avail'] + $space['trash']);

	if ($formatBytes) {
		foreach ($space as $k => $v) {
			$space[$k] = formatBytes($v * 1024);
		}
	}
	return $space;
}

function getDrives() {
/*
	$gh = '/etc/greyhole.conf';
	$dat = "";
	$fp = fopen($gh,'r');
	if (!$fp) die("Access to /etc/greyhole.conf denied");
	while (!feof($fp)) {
		$dat .= fread($fp,128);
	}
	fclose($fp);
	$dat = str_split("\n",$dat);
	foreach ($dat as $l) {
		if (substr($l,0,10) == "storage_po") {
			$m = str_split(" = ",$l);
			$n = str_split(",",$m[1]);
			$drives[] = $n[0];
		}
	}
*/
	global $stats;

	foreach ($stats as $sp_drive => $stat) {
		if ($sp_drive == 'Total') continue;
		$drives[] = $sp_drive;
	}

	return $drives;
}

function formatBytes($size, $precision = 2)
{
    $unit = ['B','KB','MB','GB'];
    for($i = 0; $size >= 1024 && $i < count($unit)-1; $i++){
        $size /= 1024;
    }

    return round($size, $precision).' '.$unit[$i];
}


function getSambaShares() {
	exec("/usr/bin/testparm -sl 2>/dev/null | grep '\[' | grep -vi '\[global]'", $output);
	$all_samba_shares = [];
	foreach ($output as $line) {
	    if (preg_match('/\s*\[(.+)]\s*$/', $line, $re)) {
	        $share_name = $re[1];
	        if (array_contains(ConfigHelper::$trash_share_names, $share_name)) {
	            $share_options = SharesConfig::getConfigForShare(CONFIG_TRASH_SHARE);
	            $share_options['is_trash'] = TRUE;
	        } else {
	            $share_options = SharesConfig::getConfigForShare($share_name);
	        }
	        if (empty($share_options)) {
	            $share_options['landing_zone'] = exec("/usr/bin/testparm -sl --parameter-name='path' --section-name=" . escapeshellarg($share_name) . " 2>/dev/null");
	            $share_options[CONFIG_NUM_COPIES . '_raw'] = '0';
	        }
	        $share_options['vfs_objects'] = exec("/usr/bin/testparm -sl --parameter-name='vfs objects' --section-name=" . escapeshellarg($share_name) . " 2>/dev/null");
	        if (empty($share_options['landing_zone'])) {
	            continue;
	        }
	        $all_samba_shares[$share_name] = $share_options;
	    }
	}
	natksort($all_samba_shares);
	return $all_samba_shares;
}

function getShareInfo($path) {
	$sambainfo = getSambaShares();
	$out = [];
	$path = trim($path,"/");
	$pathsplit = preg_split("/\//",$path);
	$out['sharename'] = trim($pathsplit[0],"/");
	unset($pathsplit[0]);
	$out['landing_zone'] = $sambainfo[$out['sharename']]['landing_zone'];
	$out['landing_path'] = $out['landing_zone']."/".implode("/",$pathsplit);
	return $out;
}


function greyholeFsck($path) {
	$path = dirname($path);
	// schedule fsck with specified directory and scan for orphaned files
	// The files we restored from trash are orphaned until this is ran.
	exec("/usr/bin/greyhole --fsck --dir=\"".$path."\" --find-orphaned-files", $output);
	return implode("<br />\n",$output);
}

