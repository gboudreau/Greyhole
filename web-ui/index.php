<?php
include_once('functions.inc.php');
$help_buttons = array();

// @TODO Remove
global $config_file, $smb_config_file;
$config_file = '/Users/bougu/Documents/Temp/greyhole.conf';
$smb_config_file = '/Users/bougu/Documents/Temp/smb.conf';
// End @TODO Remove

$samba_options_ok = FALSE;
$found1 = exec("grep -i '^[[:blank:]]*unix extensions[[:blank:]]*=[[:blank:]]*no[[:blank:]]*\$' " . escapeshellarg($smb_config_file));
$found2 = exec("grep -i '^[[:blank:]]*wide links[[:blank:]]*=[[:blank:]]*yes[[:blank:]]*\$' " . escapeshellarg($smb_config_file));
if (!empty($found1) && !empty($found2)) {
    $samba_options_ok = TRUE;
}

parse_config();
foreach ($GLOBALS as $name => $value) {
    global ${$name};
}

// Look for (and remove) the Trash share
foreach ($all_samba_shares as $key => $share_name) {
    if (array_search($share_name, $attic_share_names) !== FALSE) {
        $trash_share = $share_name;
        unset($all_samba_shares[$key]);
    }
}
natcasesort($all_samba_shares);

?><!DOCTYPE html PUBLIC "-//W3C//DTD XHTML 1.0 Transitional//EN"
"http://www.w3.org/TR/xhtml1/DTD/xhtml1-transitional.dtd">
<html xmlns="http://www.w3.org/1999/xhtml">
<head>
    <meta http-equiv="Content-Type" content="text/html; charset=utf-8" />
    <title>Greyhole-UI - Greyhole Configuration Tool</title>
    <link type="text/css" href="css/start/jquery-ui-1.8.14.custom.css" rel="stylesheet" />
    <style type="text/css">
        .help_button img {
            position: relative;
            bottom: -8px;
            padding-left: 30px;
        }
        .dialog_to_be {
            display: none;
        }
        .help_dialog {
            font-size: smaller;
        }
        .filename {
            font-weight: bold;
        }
        em {
            font-style: italic;
        }
        #shares_accordion {
            width: 800px;
        }
        #mysql_options .field {
            height: 32px;
            display: block;
        }
        #mysql_options label {
            float: left;
            width: 130px;
            padding-top: 5px;
        }
    </style>
    <script type="text/javascript" src="js/jquery-1.5.1.min.js"></script>
    <script type="text/javascript" src="js/jquery-ui-1.8.14.custom.min.js"></script>
    <script type="text/javascript">
    	$(function() {
    		$("#tabs").tabs();
    		$("#shares_accordion").accordion();
    		
    		// Welcome dialog
    		// @TODO Only show it if Geyhole is not configured properly
    		if (false) {
        		$("#dialog_welcome").dialog({width: 400, modal: true, buttons: {
        		    "Yes": function() {
        		        alert('@TODO');
        		        $(this).dialog("close");
        		    },
        		    "No": function() {
        		        $(this).dialog("close");
        		    }
        		}});
    		}

            if ($("#set_samba_options")) {
        		$("#set_samba_options").button().click(function() {
        		    alert('@TODO');
        		});
            }
            if ($("#create_database_button")) {
        		$("#create_database_button").button().click(function() {
        		    alert('@TODO Create Greyhole user & database');
        		    $('#real_db_fields').show();
        		    $('#root_db_fields').hide();
        		});
            }
    	});
	</script>
</head>
<body>
    <div id="dialog_welcome" class="dialog_to_be" title="Welcome to Greyhole!">
    	<p>It looks like you're new to Greyhole.</p>
    	<p>Would you like to get a walkthrough of the necessary steps you'll need to perform in order to get a working Greyhole install ?</p>
    </div>
    <div id="tabs">
    	<ul>
    		<li><a href="#tabs-1">Samba Shares</a></li>
    		<li><a href="#tabs-2">Samba Users</a></li>
    		<li><a href="#tabs-3">Greyhole Configuration</a></li>
    		<li><a href="#tabs-4">Greyhole Storage Pool</a></li>
    		<li><a href="#tabs-5" id="apply_changes_tab_button" style="color: black">Apply Changes...</a></li>
    	</ul>
    	<div id="tabs-1">
	        <h2>Samba Options</h2>
    	    <p id="samba_options">
    	        Set required Samba options to allow Greyhole to work properly:
    	        <?php if ($samba_options_ok): ?>
        	        <em>Done</em>
	            <?php else: ?>
        	        <button id="set_samba_options">Set</button>
                <?php endif; ?>
    	        <?php echo getHelpButton('samba_options') ?>
    	        <div id="help_samba_options" class="help_dialog dialog_to_be" title="Required Samba Options">
    	            The following options are required for Greyhole, and need to appear in the <em>[global]</em> section of your <span class="filename">/etc/samba/smb.conf</span> file:
<pre><code>    unix extensions = no
    wide links = yes
</code></pre>
    	        </div>
    	    </p>
    	    <hr class="section_separator" />
	        <h2>Existing Samba Shares</h2>
    	    <p>
    	        <div id="help_num_copies" class="help_dialog dialog_to_be large" title="Number of extra copies">
    	            <p><strong>Extra file copies is the setting that allows you to define on how many drives you'd like to keep copies of your files.</strong></p>
    	            <p>Choose 0 if you'd like no extra copies for the files in this share. Each file in this share will reside on a single drive, but the different files can still be spread accross all your drives, to keep the free space balanced.<br/>
    	            <em>With 0 extra copies, if one of your drive fails, you might loose some files.</em></p>
    	            <p>Choose 1 if you'd like one extra copy created for all files in this share. Each file in this share will reside on any two drives.<br/>
    	            <em>With one extra copies, you won't loose any files if one drive fails, but you might loose some if multiple drive fails simultaneously.</em></p>
    	            <p>Choose <em>Always Maximum</em> to keep a copy of the files in this share on all your drives.<br/>
    	            <em>With that setting, you won't loose any files unless all your drives fail simultaneously!</em></p>
    	        </div>
    	        <div id="help_gh_enabled_share" class="help_dialog dialog_to_be" title="Enable Greyhole on a Share">
    	            <p><strong>For Greyhole to work with files on this share, you need to enable it.</strong></p>
    	            <p>This will add this share in greyhole.conf, and add the following two lines in the [ShareName] section of your <span class="filename">/etc/samba/smb.conf</span> file:
<pre><code>    vfs objects = greyhole
    dfree command = /usr/bin/greyhole-dfree
</code></pre></p>
    	        </div>
    	        <div id="help_gh_delete_share" class="help_dialog dialog_to_be" title="Delete a Share">
    	            <p>This will delete this share from your <span class="filename">/etc/samba/smb.conf</span> file.</p>
    	            <p><em>It will NOT delete your data!</em></p>
    	        </div>
    	        <div id="shares_accordion">
        	        <?php foreach ($all_samba_shares as $share_name):
        	            $enabled = isset($shares_options[$share_name]);
        	            if ($enabled) {
        	                $num_extra_copies = $shares_options[$share_name]['num_copies']-1;
        	            } else {
        	                $num_extra_copies = 0;
        	            }
        	            ?>
        	            <h3><a href="#"><?php echo $share_name ?><br/></a></h3>
                    	<div>
                    		<p>
                    		    <label for="share_<?php echo $share_name ?>_gh_enabled">Greyhole Enabled</label>
                    		    <input type="checkbox" id="share_<?php echo $share_name ?>_gh_enabled" name="gh_enabled[]" value="<?php echo $share_name ?>" <?php echo ($enabled ? 'checked="checked"' : '') ?>/>
                    		    <?php echo getHelpButton('gh_enabled_share') ?>
                    		</p>
                    		<p>
                    		    <label for="share_<?php echo $share_name ?>_num_copies">Number of extra copies</label>
                    		    <select id="share_<?php echo $share_name ?>_num_copies" name="gh_num_copies_<?php echo $share_name ?>">
                    		        <?php
                    		        if (!empty($storage_pool_directories)) {
                    		            $max = count($storage_pool_directories)-1;
                    		        } else {
                    		            $max = 10;
                    		        }
                    		        for ($i=0; $i<=$max; $i++): ?>
                    		            <option value="<?php echo $i ?>" <?php echo ($num_extra_copies == $i ? 'selected="selected"' : '') ?>><?php echo $i ?></option>
                		            <?php endfor; ?>
                		            <option value="999">Always Maximum</option>
                		        </select>
                    	        <?php echo getHelpButton('num_copies') ?>
                    		</p>
                    		<p>
                    		    <label for="share_<?php echo $share_name ?>_delete"><button id="share_<?php echo $share_name ?>_delete">Delete...</button> this share</label>
                    		    <script type="text/javascript">
                    		        $('#share_<?php echo $share_name ?>_delete').button().click(function() {
                            		    alert('@TODO');
                            		});
                    		    </script>
                    		    <?php echo getHelpButton('gh_delete_share') ?>
                    		</p>
                    	</div>
        	        <?php endforeach; ?>
                </div>
    	    </p>
    	</div>
    	<div id="tabs-2">
    	    <h2>Not yet available</h2>
    		<p>This should be available in future versions of Greyhole-UI.</p>
    	</div>
    	<div id="tabs-3">
	        <h2>MySQL Options</h2>
	        <div id="help_gh_mysql" class="help_dialog dialog_to_be" title="MySQL Database">
	            <p>
	                This database will contain some settings, and the tasks queue that Greyhole will work through sequentially.<br/>
	                It should be a local database, to keep latency to a minimum.
	            </p>
	        </div>
    	    <p id="mysql_options">
    	        Greyhole requires a MySQL database to work with.<?php echo getHelpButton('gh_mysql') ?><br/>
                <br/>
    	        <span class="field"><label for="db_host">MySQL Host:</label>
    	            <input type="text" id="db_host" name="db_host" value="<?php echo $db_host ?>" /></span>
    	        <?php
    	        $c = mysql_connect($db_host, $db_user, $db_pass);
    	        if ($c) {
    	            $d = mysql_select_db($db_name);
    	        }
    	        if ($c && $d) {
    	            $database_ok = TRUE;
    	        } else if ($c) {
    	            $user_ok = TRUE;
    	        } else {
    	            $user_ok = FALSE;
    	            $database_ok = FALSE;
    	        }
    	        ?>
    	        <span id="real_db_fields" <?php if (!$database_ok) { echo 'style="display:none"'; } ?>>
        	        <span class="field"><label for="db_user">MySQL User:</label>
        	            <input type="text" id="db_user" name="db_user" value="<?php echo $db_user ?>" /></span>
        	        <span class="field"><label for="db_pass">Password:</label>
        	            <input type="text" id="db_pass" name="db_pass" value="<?php echo $db_pass ?>" /></span>
        	        <span class="field"><label for="db_name">Database:</label>
        	            <input type="text" id="db_name" name="db_name" value="<?php echo $db_name ?>" /></span>
    	        </span>
    	        <span id="root_db_fields" <?php if ($database_ok) { echo 'style="display:none"'; } ?>>
        	        <span class="field"><label for="db_root_user">MySQL User:</label>
    	                <input type="text" id="db_root_user" name="db_root_user" value="root" /></span>
        	        <span class="field"><label for="db_root_pass">Password:</label>
        	            <input type="text" id="db_root_pass" name="db_root_pass" value="" /></span>
        	        <br/>
    	            <?php if ($user_ok): ?>
            	        Enter the MySQL root user password above, and click the button below to create a new MySQL database for Greyhole.<br/>
        	        <?php else: ?>
        	            Enter the MySQL root user password above, and click the button below to create a new MySQL user and database for Greyhole.<br/>
        	        <?php endif; ?>
        	        <br/>
        	        <button id="create_database_button">Create Greyhole Database</button>
    	        </span>
            </p>
            
            <h2>More</h2>
            <p>
                Future versions of Greyhole-UI will allow more customizations to be done using this interface, but until then, 
                you'll need to manually edit /etc/greyhole.conf to further configure Greyhole.
            </p>
    	</div>
    	<div id="tabs-4">
    		<p>Morbi tincidunt, dui sit amet facilisis feugiat, odio metus gravida ante, ut pharetra massa metus id nunc. Duis scelerisque molestie turpis. Sed fringilla, massa eget luctus malesuada, metus eros molestie lectus, ut tempus eros massa ut dolor. Aenean aliquet fringilla sem. Suspendisse sed ligula in ligula suscipit aliquam. Praesent in eros vestibulum mi adipiscing adipiscing. Morbi facilisis. Curabitur ornare consequat nunc. Aenean vel metus. Ut posuere viverra nulla. Aliquam erat volutpat. Pellentesque convallis. Maecenas feugiat, tellus pellentesque pretium posuere, felis lorem euismod felis, eu ornare leo nisi vel felis. Mauris consectetur tortor et purus.</p>
    	</div>
    	<div id="tabs-5">
    		<p>Morbi tincidunt, dui sit amet facilisis feugiat, odio metus gravida ante, ut pharetra massa metus id nunc. Duis scelerisque molestie turpis. Sed fringilla, massa eget luctus malesuada, metus eros molestie lectus, ut tempus eros massa ut dolor. Aenean aliquet fringilla sem. Suspendisse sed ligula in ligula suscipit aliquam. Praesent in eros vestibulum mi adipiscing adipiscing. Morbi facilisis. Curabitur ornare consequat nunc. Aenean vel metus. Ut posuere viverra nulla. Aliquam erat volutpat. Pellentesque convallis. Maecenas feugiat, tellus pellentesque pretium posuere, felis lorem euismod felis, eu ornare leo nisi vel felis. Mauris consectetur tortor et purus.</p>
    	</div>
    </div>
</body>
</html>