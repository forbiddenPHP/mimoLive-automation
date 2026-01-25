<?php
$base = 'hosts/master/documents/forbiddenPHP/';
$layer = $base . 'layers/MEv/variants/dyn';
$sourceColor = $base . 'sources/Color';
$sourceCam = $base . 'sources/c1-with-bluescreen';

// Demo 1: Small box top left with crop using Color source
setValue($layer, [
    'input-values' => [
        'tvIn_VideoSourceAImage' => getID($sourceColor),
        ...mimoPosition('tvGroup_Geometry__Window', 400, 300, 50, 50, $base),
        ...mimoCrop('tvGroup_Geometry__Crop', '10%', '10%', '5%', '5%'),
    ]
]);
setSleep(2);

// Demo 2: Large box centered with pixel-based crop using camera source
setValue($layer, [
    'input-values' => [
        'tvIn_VideoSourceAImage' => getID($sourceCam),
        ...mimoPosition('tvGroup_Geometry__Window', 1200, 800, 140, 360, $base),
        ...mimoCrop('tvGroup_Geometry__Crop', 50, 50, 100, 100, $sourceCam),
    ]
]);
setSleep(2);

// Demo 3: Narrow box on the right with Color source
setValue($layer, [
    'input-values' => [
        'tvIn_VideoSourceAImage' => getID($sourceColor),
        ...mimoPosition('tvGroup_Geometry__Window', 300, 600, 240, 1500, $base),
    ]
]);
setSleep(2);

// Demo 4: Wide box at the bottom with Color source
setValue($layer, [
    'input-values' => [
        'tvIn_VideoSourceAImage' => getID($sourceColor),
        ...mimoPosition('tvGroup_Geometry__Window', 1600, 200, 800, 160, $base),
    ]
]);
setSleep(2);

// Demo 5: Almost fullscreen with Color source
setValue($layer, [
    'input-values' => [
        'tvIn_VideoSourceAImage' => getID($sourceColor),
        ...mimoPosition('tvGroup_Geometry__Window', 1840, 1000, 40, 40, $base),
    ]
]);
setSleep(2);

// Back to small with Color source
setValue($layer, [
    'input-values' => [
        'tvIn_VideoSourceAImage' => getID($sourceColor),
        ...mimoPosition('tvGroup_Geometry__Window', 400, 300, 50, 50, $base),
    ]
]);
setSleep(2);

// Demo 6: Using percentages with crop and Color source
setValue($layer, [
    'input-values' => [
        'tvIn_VideoSourceAImage' => getID($sourceColor),
        ...mimoPosition('tvGroup_Geometry__Window', '50%', '40%', '10%', '25%', $base),
        ...mimoCrop('tvGroup_Geometry__Crop', '15%', '15%', '10%', '10%'),
    ]
]);
setSleep(2);

// Demo 7: Small percentage box with camera, reset crop
setValue($layer, [
    'input-values' => [
        'tvIn_VideoSourceAImage' => getID($sourceCam),
        ...mimoPosition('tvGroup_Geometry__Window', '20%', '30%', '35%', '40%', $base),
        ...mimoCrop('tvGroup_Geometry__Crop', 0, 0, 0, 0, $sourceCam),
    ]
]);
setSleep(2);

// End of script
setLive($base.'layers/JoPhi DEMOS/variants/stop');

