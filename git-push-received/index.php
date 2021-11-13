<?php

// Github webhook for push in https://github.com/gboudreau/Greyhole
// Ref: https://github.com/gboudreau/Greyhole/settings/hooks/312985031

if (empty($_SERVER['HTTP_X_HUB_SIGNATURE_256'])) {
    header('HTTP/1.1 403 Forbidden');
    die('Nope.');
}

list($algo, $gh_signature) = explode('=', $_SERVER['HTTP_X_HUB_SIGNATURE_256']);

$post_data = file_get_contents('php://input');
$signature = hash_hmac('sha256', $post_data, getenv('GH_SECRET_TOKEN'));

//error_log("Signature received: " . $gh_signature);
//error_log("Signature calculated: $signature");
//error_log("Request received: $post_data");

if ($algo !== 'sha256' || !hash_equals($signature, $gh_signature)) {
    header('HTTP/1.1 403 Forbidden');
    die('Nope.');
}

$data = json_decode($post_data);

if ($data->ref !== 'refs/heads/master') {
    header('HTTP/1.1 406 Not Acceptable');
    die('Nope.');
}

error_log("Received push webhook for Greyhole:master; will build using bin/greyhole-docker-build-develop.sh");

echo "OK! Will trigger a docker build for greyhole, and tag it :develop ...";
file_put_contents("../docker-build.trigger/ping", $post_data);
