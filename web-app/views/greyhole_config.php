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

<?php if (defined('IS_INITIAL_SETUP')) : ?>
    <h2 class="mt-8 mb-3">Configure Greyhole</h2>
    <div class="mb-4">
        Change any config option below, based on your server, situation and preferences.<br/>
        Navigate the different sections using the tabs, and use the <code>Continue</code> button once you're done.
    </div>
<?php else : ?>
    <h2 class="mt-8 mb-4">Greyhole Config</h2>
<?php endif; ?>

<?php
global $configs;
include 'web-app/config_definitions.inc.php';
?>
<ul class="nav nav-tabs" id="myTabGreyhole" role="tablist" data-name="pagegh">
    <?php $first = empty($_GET['pagegh']); foreach ($configs as $i => $config) : $active = $first || @$_GET['pagegh'] == 'id_' . md5($config->name) . '_tab'; if ($active) $selected_tab = $i; ?>
        <li class="nav-item">
            <a class="nav-link <?php echo $active ? 'active' : '' ?>"
               id="id_<?php echo md5($config->name) ?>_tab"
               data-toggle="tab"
               href="#id_<?php echo md5($config->name) ?>"
               role="tab"
               aria-controls="id_<?php echo md5($config->name) ?>"
               aria-selected="<?php echo $active ? 'true' : 'false' ?>"><?php phe($config->name) ?></a>
        </li>
    <?php $first = FALSE; endforeach; ?>
</ul>
<div class="tab-content" id="myTabContentGreyhole">
    <?php foreach ($configs as $i => $config) : ?>
        <div class="tab-pane fade <?php if ($i == $selected_tab) echo 'show active' ?>" id="id_<?php echo md5($config->name) ?>" role="tabpanel" aria-labelledby="id_<?php echo md5($config->name) ?>_tab">
            <?php echo get_config_html($config) ?>
        </div>
    <?php endforeach; ?>
</div>
