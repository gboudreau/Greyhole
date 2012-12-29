#!/usr/bin/php
<?php

$username_password = file_get_contents('.github_userpwd');

// Delete old downloads first
foreach (json_decode(file_get_contents('https://api.github.com/repos/gboudreau/Greyhole/downloads')) as $download) {
    delete_download($download->id, $download->name);
}

// Find files to upload
$version = $argv[1];
$build_number = $argv[2];
foreach (glob("release/*$version-$build_number*") as $file) {
    $filename = basename($file);
    if (strpos($filename, 'hda-greyhole-') !== FALSE || strpos($filename, '.src.rpm') !== FALSE || strpos($filename, 'greyhole-web-app') !== FALSE || strpos($filename, '.armv5tel.') !== FALSE) {
        // Skip those files
        continue;
    }
    $file_type = get_file_type($filename);
    $description = "Greyhole $version-$build_number ($file_type)";

    echo "Uploading $filename with description $description...\n";
    // Create the download metadata on Github
    $response = create_download($filename, filesize($file), $description);
    // Upload the actual file on Amazon S3
    upload_file($file, $response);
}

function delete_download($id, $filename) {
    global $username_password;
    $url = "https://api.github.com/repos/gboudreau/Greyhole/downloads/$id";
    echo "DELETE $url ($filename)\n";
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'DELETE');
    curl_setopt($ch, CURLOPT_HEADER, 0);
    curl_setopt($ch, CURLOPT_USERPWD, $username_password);
    curl_exec($ch);
    $response_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    echo "  $response_code\n";
    curl_close($ch);
}

function get_file_type($file) {
    if (strpos($file, '.tar.gz') !== FALSE) {
        return 'Source';
    }

    if (strpos($file, '.i386.') !== FALSE) {
        $arch = 'i386';
    } else if (strpos($file, '.amd64.') !== FALSE) {
        $arch = 'amd64';
    } else if (strpos($file, '.x86_64.') !== FALSE) {
        $arch = 'x86_64';
    } else if (strpos($file, '.armv5tel.') !== FALSE) {
        $arch = 'armv5tel';
    }

    if (strpos($file, '.deb') !== FALSE) {
        $ext = 'DEB';
        $OS = 'Ubuntu/Debian';
    } else if (strpos($file, '.rpm') !== FALSE) {
        $ext = 'RPM';
        $OS = 'Fedora/CentOS';
    }
    
    return "$OS $arch $ext";
}

function create_download($filename, $size, $description) {
    global $username_password;
    $request_body = '{"name": "' . $filename . '","size": ' . $size . ',"description": "' . $description . '"}';
    
    $url = "https://api.github.com/repos/gboudreau/Greyhole/downloads";
    echo "  POST $url\n";
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_CUSTOMREQUEST, "POST");
    curl_setopt($ch, CURLOPT_POSTFIELDS, $request_body);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
    curl_setopt($ch, CURLOPT_HEADER, 0);
    curl_setopt($ch, CURLOPT_USERPWD, $username_password);
    $response = curl_exec($ch);
    curl_close($ch);
    return json_decode($response);
}

function upload_file($file, $response) {
    $url = "https://github.s3.amazonaws.com/";
    echo "  POST $url\n";
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_POST, 1);
    curl_setopt($ch, CURLOPT_POSTFIELDS, array(
        'key' => $response->path,
        'acl' => $response->acl,
        'success_action_status' => 201,
        'Filename' => $response->name,
        'AWSAccessKeyId' => $response->accesskeyid,
        'Policy' => $response->policy,
        'Signature' => $response->signature,
        'Content-Type' => $response->mime_type,
        'file' => '@'.$file,
    ));
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
    curl_setopt($ch, CURLOPT_HEADER, 0);
    $response = curl_exec($ch);
    curl_close($ch);
}

echo("Done.\n");
