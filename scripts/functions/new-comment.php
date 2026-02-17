<?php
comments:
    $username = $_GET['username'] ?? 'anonymous';
    $comment = $_GET['message'] ?? null;
    $userimageurl = $_GET['userimageurl'] ?? null;
    $plattform = $_GET['plattform'] ?? null;
    $favorite = $_GET['favorite'] ?? false;

    pushComment('hosts/master/comments/new', [
        'username' => $username,
        'comment' => $comment,
        'userimageurl' => $userimageurl,
        'platform' => $plattform,
        'favorite' => $favorite
    ]);

