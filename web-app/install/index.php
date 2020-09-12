<?php
/*
Copyright 2013-2020 Guillaume Boudreau

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

include(__DIR__ . '/../init.inc.php');

define('IS_INITIAL_SETUP', TRUE);

$step = @$_REQUEST['step'];
if (empty($step)) {
    $step = 1;
}
$num_steps = 6;

if ($step > $num_steps) {
    $page_title = "Done!";
} else {
    $page_title = "Step $step / $num_steps";
}
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, shrink-to-fit=no">
    <?php if ($is_dark_mode) : ?>
        <link rel="stylesheet" href="https://stackpath.bootstrapcdn.com/bootswatch/4.5.2/darkly/bootstrap.min.css" integrity="sha384-nNK9n28pDUDDgIiIqZ/MiyO3F4/9vsMtReZK39klb/MtkZI3/LtjSjlmyVPS3KdN" crossorigin="anonymous">
    <?php else : ?>
        <link rel="stylesheet" href="https://stackpath.bootstrapcdn.com/bootstrap/4.5.2/css/bootstrap.min.css" integrity="sha384-JcKb8q3iqJ61gNV9KGb8thSsNjpSL0n8PARn9HuZOnIxN0hoP+VmmDGMN5t9UJ0Z" crossorigin="anonymous">
    <?php endif; ?>
    <script src="../scripts.js"></script>
    <link rel="stylesheet" href="../styles.css">
    <link rel="shortcut icon" type="image/png" href="../favicon.png" sizes="64x64">
    <title>Greyhole Initial Setup Wizard - <?php phe($page_title) ?></title>
</head>
<body class="<?php if ($is_dark_mode) echo "dark" ?>">

<div class="container-fluid">
    <nav class="navbar navbar-expand-md navbar-dark bg-dark">
        <div class="navbar-brand">
            Greyhole Initial Setup Wizard
        </div>
    </nav>

    <?php if ($step <= $num_steps) : ?>
        <div class="progress mt-3 mb-3" style="height: 25px">
            <div class="progress-bar" role="progressbar" style="width: <?php echo round($step / $num_steps * 100) ?>%" aria-valuenow="<?php echo $step ?>" aria-valuemin="0" aria-valuemax="<?php echo $num_steps ?>">Step <?php echo "$step / $num_steps" ?></div>
        </div>
    <?php endif; ?>

    <?php include "web-app/install/step" . ((int) $step) . '.inc.php' ?>

    <div class="mt-4">
        <?php if ($step <= $num_steps) : ?>
            <button id="continue-button" class="btn btn-primary" onclick="continueInstall(this, <?php echo $step ?>)">Continue</button>
        <?php endif; ?>
    </div>
</div>

<div id="footer-padding"></div>

<script src="https://cdn.jsdelivr.net/npm/jquery@3.5.1/dist/jquery.min.js" integrity="sha256-9/aliU8dGd2tb6OSsuzixeV4y/faTqgFtohetphbbj0=" crossorigin="anonymous"></script>
<script src="https://cdn.jsdelivr.net/npm/popper.js@1.16.1/dist/umd/popper.min.js" integrity="sha384-9/reFTGAW83EW2RDu2S0VKaIzap3H66lZH81PoYlFhbGU+6BZp6G7niu735Sk7lN" crossorigin="anonymous"></script>
<script src="https://stackpath.bootstrapcdn.com/bootstrap/4.5.2/js/bootstrap.min.js" integrity="sha384-B4gt1jrGC7Jh4AgTPSdUtOBvfO8shuf57BaghqFfPlYxofvL8/KUEfYiJOMMV+rV" crossorigin="anonymous"></script>
</body>
</html>
