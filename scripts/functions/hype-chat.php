<?php
hype_chat:
    $username = $_GET['username'] ?? 'anonymous';
    $amount = $_GET['amount'] ?? '0';
    $currency = $_GET['currency'] ?? 'USD';
    $level = $_GET['level'] ?? 'ONE';

    // Dynamic document base path
    $doc = 'hosts/master/documents/' . array_keys(namedAPI_get('hosts/master/documents'))[0] . '/';

    // Frame 1: Set text + style (layers are off)
    $text = "$username sent a Hype Chat! ($amount $currency)";
    setAndAdjustAnnotationText($doc.'layers/Annotation Text', $text, 800, 100, 1720, 200, 5);
    setValue($doc.'layers/Annotation Text', ['input-values' => [
        'tvGroup_Background__Color_1' => mimoColor('#1a1025'),
        'tvGroup_Background__Color_2' => mimoColor('#2d1b4e'),
    ]]);
    setSleep(0, false);

    // Frame 2: Show text + play audio
    setLive($doc.'layers/Annotation Text');
    setLive($doc.'layers/Annotation Audio/variants/hype-chat');
    setSleep(5, false);

    // Frame 3: Hide layers
    setOff($doc.'layers/Annotation Text');
    setOff($doc.'layers/Annotation Audio');
    setSleep(1, false);
