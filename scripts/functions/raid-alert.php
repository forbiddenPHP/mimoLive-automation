<?php
raid_alert:
    $username = $_GET['username'] ?? 'anonymous';
    $viewers = intval($_GET['viewers'] ?? 0);
    $userimageurl = $_GET['userimageurl'] ?? null;

    // Dynamic document base path
    $doc = 'hosts/master/documents/' . array_keys(namedAPI_get('hosts/master/documents'))[0] . '/';

    // Download user image if available
    $has_image = false;
    if ($userimageurl) {
        $image_data = @file_get_contents($userimageurl);
        if ($image_data !== false) {
            $image_dir = __DIR__ . '/../../buffer';
            if (!is_dir($image_dir)) @mkdir($image_dir, 0755, true);
            $image_path = $image_dir . '/user_image.jpg';
            if (file_put_contents($image_path, $image_data) !== false) {
                $has_image = true;
                // Set filepath on user_image source
                setValue($doc.'sources/user_image', ['filepath' => realpath($image_path)]);
            }
        }
    }

    // Frame 1: Set text + style (layers are off) + image source if available
    $text = "$username is raiding with $viewers viewers!";
    setAndAdjustAnnotationText($doc.'layers/Annotation Text', $text, 800, 100, 1720, 200, 5);
    setValue($doc.'layers/Annotation Text', ['input-values' => [
        'tvGroup_Background__Color_1' => mimoColor('#1a1025'),
        'tvGroup_Background__Color_2' => mimoColor('#2d1b4e'),
    ]]);
    setSleep(0, false);

    // Frame 2: Show text + play audio + show image if available
    setLive($doc.'layers/Annotation Text');
    setLive($doc.'layers/Annotation Audio/variants/raid-alert');
    if ($has_image) {
        setLive($doc.'layers/Annotation Image');
    }
    setSleep(5, false);

    // Frame 3: Hide layers
    setOff($doc.'layers/Annotation Text');
    setOff($doc.'layers/Annotation Audio');
    if ($has_image) {
        setOff($doc.'layers/Annotation Image');
    }
    setSleep(1, false);
