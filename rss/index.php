<?php
header('Content-Type: application/rss+xml; charset=UTF-8');
$url = "http://www.pommepause.com/blog/category/open-source/greyhole/feed/";
$rss = file_get_contents($url);
$rss = str_replace($url, 'http://www.greyhole.net/rss/', $rss);
$rss = str_replace('Guillaume Boudreau Geek Blog &#187; Greyhole', 'Greyhole News', $rss);
$rss = str_replace('link>http://www.pommepause.com/blog</link', 'link>http://www.greyhole.net</link', $rss);
$rss = str_replace('My Geek Life', 'Greyhole News', $rss);
echo $rss;
?>