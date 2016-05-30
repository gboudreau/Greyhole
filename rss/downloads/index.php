<?php
header('Content-Type: application/rss+xml; charset=UTF-8');
$url = "https://github.com/gboudreau/Greyhole/releases.atom";
$rss = file_get_contents($url);
$rss = str_replace($url, 'http://www.greyhole.net/rss/downloads/', $rss);
echo $rss;
