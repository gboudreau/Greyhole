<?php
header('Content-Type: application/atom+xml; charset=UTF-8');
$url = "https://github.com/gboudreau/Greyhole/commits/master.atom";
$rss = file_get_contents($url);
$rss = str_replace($url, 'http://www.greyhole.net/rss/commits/', $rss);
echo $rss;
