<?php
/*
Copyright 2020 Guillaume Boudreau

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
?>

<h2 class="mt-8">
    Trash Manager
    <small id="trashman-current-dir-header"></small>
</h2>

<input id="trashman-current-dir" type="hidden" value="." />

<table id="trashman-table">
    <thead>
    <tr>
        <th>Path</th>
        <th>Size</th>
        <th># Files Copies</th>
        <th>Last Modified</th>
        <th></th>
    </tr>
    </thead>
</table>

<div id="modal-trashman-delete" class="modal" tabindex="-1" role="dialog">
    <div class="modal-dialog" role="document">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">Delete forever?</h5>
                <button type="button" class="close" data-dismiss="modal" aria-label="Close">
                    <span aria-hidden="true">&times;</span>
                </button>
            </div>
            <div class="modal-body">
                Are you sure that you want to delete <br/>
                <strong><span class="copies"></span></strong> file copie(s)<br/>
                totaling <strong><span class="size"></span></strong>
                <br/>from Trash/<strong><span class="path"></span></strong> ?
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-danger" onclick="trashmanConfirmDelete(this)">Delete forever</button>
                <button type="button" class="btn btn-secondary" data-dismiss="modal">Cancel</button>
            </div>
        </div>
    </div>
</div>

<div id="modal-trashman-restore" class="modal" tabindex="-1" role="dialog">
    <div class="modal-dialog" role="document">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">Restore?</h5>
                <button type="button" class="close" data-dismiss="modal" aria-label="Close">
                    <span aria-hidden="true">&times;</span>
                </button>
            </div>
            <div class="modal-body">
                Are you sure that you want to restore <br/>
                <strong><span class="copies"></span></strong> file(s)<br/>
                totaling <strong><span class="size"></span></strong>
                <br/>into Samba/<strong><span class="path"></span></strong> ?
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-success" onclick="trashmanConfirmRestore(this)">Restore</button>
                <button type="button" class="btn btn-secondary" data-dismiss="modal">Cancel</button>
            </div>
        </div>
    </div>
</div>

<?php

return;

// We could use _SERVER['GET'] but we already use $query
// for ['page'] and this works fine
$ext = isset($query['path']) ? $query['path'] : "";
$view = isset($query['view']) ? $query['view'] : "";
$drive = isset($query['drive']) ? $query['drive'] : "";
$action = isset($query['action']) ? $query['action'] : "";
$confirm = isset($query['confirm']) ? $query['confirm'] : "";

if (($drive == "" || $view == "") && $action == "") {
} else {
    if ($action == "remove" && $ext != "") {
        if ($confirm != "Yes") {
            echo "<h2 class=\"mt-8\">Removing ".$ext."</h2>\n";
            if (is_dir($ext)) {
                echo "Are you sure you want to recursively destroy all copies of this directory, and the files within it?<br />\n";
            } else {
                echo "Are you sure you want to destroy all copies of this file in the trash?<br />\n";
            }
            echo "This action is not reversible!<br />\n";

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

function formatBytes($size, $precision = 2) {
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
