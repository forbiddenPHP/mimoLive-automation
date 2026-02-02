<?php
comments_test:

    // Test script for pushComment() function
    // This sends test comments to mimoLive's comment system

    pushComment('hosts/master/comments/new', [
        'username' => 'Anna Schmidt',
        'comment' => 'Super Stream heute! Macht weiter so! 🎉',
        'userimageurl' => 'https://i.pravatar.cc/150?img=1',
        'platform' => 'youtube',
        'favorite' => true
    ]);

setSleep(0.3, false);

    pushComment('hosts/master', [
        'username' => 'MaxGamer92',
        'comment' => 'Wann kommt das nächste Tutorial?',
        'userimageurl' => 'https://i.pravatar.cc/150?img=8',
        'platform' => 'twitch'
    ]);

setSleep(0.3, false);

    pushComment('hosts/master/comments/new', [
        'username' => 'Lisa_Live',
        'comment' => 'Grüße aus München! 👋',
        'userimageurl' => 'https://i.pravatar.cc/150?img=5',
        'platform' => 'facebook'
    ]);

setSleep(0.3, false);

    pushComment('hosts/master', [
        'username' => 'TechnikFan',
        'comment' => 'Welche Kamera benutzt ihr eigentlich?',
        'userimageurl' => 'https://i.pravatar.cc/150?img=12',
        'platform' => 'youtube'
    ]);

setSleep(0.3, false);

    pushComment('hosts/master', [
        'username' => 'StreamQueen',
        'comment' => 'Love it! ❤️🔥',
        'userimageurl' => 'https://i.pravatar.cc/150?img=9',
        'platform' => 'twitch',
        'favorite' => true
    ]);

setSleep(0.3, false);

    pushComment('hosts/master', [
        'username' => 'NightOwl_DE',
        'comment' => 'Bin gerade erst dazugekommen, was hab ich verpasst?',
        'userimageurl' => 'https://i.pravatar.cc/150?img=15',
        'platform' => 'twitter'
    ]);

setSleep(0.2, false);

setLive('hosts/master/documents/comments-test/layers/Scripts/variants/done');
