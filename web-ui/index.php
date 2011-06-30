<!DOCTYPE html PUBLIC "-//W3C//DTD XHTML 1.0 Transitional//EN"
"http://www.w3.org/TR/xhtml1/DTD/xhtml1-transitional.dtd">
<html xmlns="http://www.w3.org/1999/xhtml">
<head>
    <meta http-equiv="Content-Type" content="text/html; charset=utf-8" />
    <title>Greyhole-UI - Greyhole Configuration Tool</title>
    <link type="text/css" href="css/start/jquery-ui-1.8.14.custom.css" rel="stylesheet" />
    <script type="text/javascript" src="js/jquery-1.5.1.min.js"></script>
    <script type="text/javascript" src="js/jquery-ui-1.8.14.custom.min.js"></script>
    <script type="text/javascript">
    	$(function() {
    		$("#tabs").tabs();
    		$("#dialog_welcome").dialog({width: 400, modal: true});
    		$("#walk_yes").button().click(function() { alert('@TODO'); });
    		$("#walk_no").button().click(function() { $("#dialog_welcome").dialog('close'); });
    		$("#set_samba_options").button().click(function() { alert('@TODO'); });
    	});
	</script>
</head>
<body>
    <div id="dialog_welcome" title="Welcome to Greyhole!" style="display:none">
    	<p>It looks like you're new to Greyhole.</p>
    	<p>Would you like to get a walkthrough of the necessary steps you'll need to perform in order to get a working Greyhole install ?</p>
    	<p style="text-align: center"><button id="walk_yes">Yes</button><button id="walk_no">No</button></p>
    </div>
    <div id="tabs">
    	<ul>
    		<li><a href="#tabs-1">Samba Shares</a></li>
    		<li><a href="#tabs-2">Samba Users</a></li>
    		<li><a href="#tabs-3">Greyhole Configuration</a></li>
    		<li><a href="#tabs-4">Greyhole Storage Pool</a></li>
    	</ul>
    	<div id="tabs-1">
    	    <p><strong>Step 1.</strong> Set required Samba options to allow Greyhole to work properly: <button id="set_samba_options">Set</button></p>
    	</div>
    	<div id="tabs-2">
    		<p>Morbi tincidunt, dui sit amet facilisis feugiat, odio metus gravida ante, ut pharetra massa metus id nunc. Duis scelerisque molestie turpis. Sed fringilla, massa eget luctus malesuada, metus eros molestie lectus, ut tempus eros massa ut dolor. Aenean aliquet fringilla sem. Suspendisse sed ligula in ligula suscipit aliquam. Praesent in eros vestibulum mi adipiscing adipiscing. Morbi facilisis. Curabitur ornare consequat nunc. Aenean vel metus. Ut posuere viverra nulla. Aliquam erat volutpat. Pellentesque convallis. Maecenas feugiat, tellus pellentesque pretium posuere, felis lorem euismod felis, eu ornare leo nisi vel felis. Mauris consectetur tortor et purus.</p>
    	</div>
    	<div id="tabs-3">
    		<p>Mauris eleifend est et turpis. Duis id erat. Suspendisse potenti. Aliquam vulputate, pede vel vehicula accumsan, mi neque rutrum erat, eu congue orci lorem eget lorem. Vestibulum non ante. Class aptent taciti sociosqu ad litora torquent per conubia nostra, per inceptos himenaeos. Fusce sodales. Quisque eu urna vel enim commodo pellentesque. Praesent eu risus hendrerit ligula tempus pretium. Curabitur lorem enim, pretium nec, feugiat nec, luctus a, lacus.</p>
    		<p>Duis cursus. Maecenas ligula eros, blandit nec, pharetra at, semper at, magna. Nullam ac lacus. Nulla facilisi. Praesent viverra justo vitae neque. Praesent blandit adipiscing velit. Suspendisse potenti. Donec mattis, pede vel pharetra blandit, magna ligula faucibus eros, id euismod lacus dolor eget odio. Nam scelerisque. Donec non libero sed nulla mattis commodo. Ut sagittis. Donec nisi lectus, feugiat porttitor, tempor ac, tempor vitae, pede. Aenean vehicula velit eu tellus interdum rutrum. Maecenas commodo. Pellentesque nec elit. Fusce in lacus. Vivamus a libero vitae lectus hendrerit hendrerit.</p>
    	</div>
    	<div id="tabs-4">
    		<p>Morbi tincidunt, dui sit amet facilisis feugiat, odio metus gravida ante, ut pharetra massa metus id nunc. Duis scelerisque molestie turpis. Sed fringilla, massa eget luctus malesuada, metus eros molestie lectus, ut tempus eros massa ut dolor. Aenean aliquet fringilla sem. Suspendisse sed ligula in ligula suscipit aliquam. Praesent in eros vestibulum mi adipiscing adipiscing. Morbi facilisis. Curabitur ornare consequat nunc. Aenean vel metus. Ut posuere viverra nulla. Aliquam erat volutpat. Pellentesque convallis. Maecenas feugiat, tellus pellentesque pretium posuere, felis lorem euismod felis, eu ornare leo nisi vel felis. Mauris consectetur tortor et purus.</p>
    	</div>
    </div>
</body>
</html>