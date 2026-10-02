<?php
defined('_JEXEC') or die;
echo \Mitropolia\Plugin\System\MitropoliaSources\Source\Directory::render($props ?? []);
