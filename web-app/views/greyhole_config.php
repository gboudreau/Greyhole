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

<h2 class="mt-8">Greyhole Config</h2>

<?php
global $configs;
include 'web-app/config_definitions.inc.php';
?>
<ul class="nav nav-tabs" id="myTabGreyhole" role="tablist">
    <?php foreach ($configs as $i => $config) : ?>
        <li class="nav-item">
            <a class="nav-link <?php echo $i == 0 ? 'active' : '' ?>" id="id<?php echo md5($config->name) ?>-tab" data-toggle="tab" href="#id<?php echo md5($config->name) ?>" role="tab" aria-controls="id<?php echo md5($config->name) ?>" aria-selected="<?php echo $i == 0 ? 'true' : 'false' ?>"><?php phe($config->name) ?></a>
        </li>
    <?php endforeach; ?>
</ul>
<div class="tab-content" id="myTabContentGreyhole">
    <?php foreach ($configs as $i => $config) : ?>
        <div class="tab-pane fade <?php echo $i == 0 ? 'show active' : '' ?>" id="id<?php echo md5($config->name) ?>" role="tabpanel" aria-labelledby="id<?php echo md5($config->name) ?>-tab">
            <?php echo get_config_html($config) ?>
        </div>
    <?php endforeach; ?>
</div>
