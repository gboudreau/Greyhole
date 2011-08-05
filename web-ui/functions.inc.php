<?php
global $_CONSTANTS, $attic_share_names, $storage_pool_directories, $shares_options;
include_once('common.inc.php');

function getHelpButton($id) {
    global $help_buttons;
    $new_id = $id;
    $i = 1;
    while (isset($help_buttons[$new_id])) {
        $new_id = "$id_$i";
        $i++;
    }
    $help_buttons[$new_id] = TRUE;
    ?>
    <span class="help_button"><img id="help_button_<?php echo $new_id ?>" src="images/help.png" width="32" height="32" alt="Help" /></span>
    <script type="text/javascript">
	$("#help_button_<?php echo $new_id ?>").click(function() {
	    var width = 350;
	    if ($('#help_<?php echo $id ?>').hasClass('large')) {
	        width = 550;
	    }
	    $('#help_<?php echo $id ?>').dialog({width: width, modal: true});
	});
	</script>
	<?php
}
?>