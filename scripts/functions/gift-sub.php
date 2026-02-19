<?php
gift_sub:
    $username = $_GET['username'] ?? 'anonymous';
    $count = intval($_GET['giftcount'] ?? 1);
    $tier = $_GET['tier'] ?? 'Tier 1';

    // Dynamic document base path
    $doc = 'hosts/master/documents/' . array_keys(namedAPI_get('hosts/master/documents'))[0] . '/';

    // Frame 1: Set text + style (layers are off)
    $text = "$username gifted $count sub" . ($count > 1 ? 's' : '') . "! ($tier)";
    setAndAdjustAnnotationText($doc.'layers/Annotation Text', $text, 800, 100, 1720, 200, 5);
    setValue($doc.'layers/Annotation Text', ['input-values' => [
        'tvGroup_Background__Color_1' => mimoColor('#1a1025'),
        'tvGroup_Background__Color_2' => mimoColor('#2d1b4e'),
    ]]);
    setSleep(0, false);

    // Frame 2: Show text + play audio
    setLive($doc.'layers/Annotation Text');
    setLive($doc.'layers/Annotation Audio/variants/gift-sub');
    setSleep(5, false);

    // Frame 3: Hide layers
    setOff($doc.'layers/Annotation Text');
    setOff($doc.'layers/Annotation Audio');
    setSleep(1, false);
