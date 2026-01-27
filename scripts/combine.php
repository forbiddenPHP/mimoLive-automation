<?php

$base = 'hosts/master/documents/forbiddenPHP/';
$layer = $base . 'layers/MEv';
$sourceColor = $base . 'sources/Color';
$sourceCam = $base . 'sources/c1-with-bluescreen';
$stop = $base . 'layers/JoPhi DEMOS/variants/stop';

// Audio and Video
setVolume($layer, 0.5);
setValue($layer, [
    'input-values' => [
        'tvIn_VideoSourceAImage' => getID($sourceColor),
        ...mimoPosition('tvGroup_Geometry__Window', 400, 300, 50, 50, $base),
        ...mimoCrop('tvGroup_Geometry__Crop', '10%', '10%', '5%', '5%'),
    ]
]);
setSleep(2);

setVolume($layer, 1);
setValue($layer, [
    'input-values' => [
        'tvIn_VideoSourceAImage' => getID($sourceColor),
        ...mimoPosition('tvGroup_Geometry__Window', '100%', '100%', 0, 0, $base),
        ...mimoCrop('tvGroup_Geometry__Crop', '0%', '0%', '0%', '0%'),
    ]
]);
setSleep(2);

setLive($stop);