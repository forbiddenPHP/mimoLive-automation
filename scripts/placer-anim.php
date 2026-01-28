<?php
$base = 'hosts/master/documents/forbiddenPHP/';
$source = $base.'sources/Color';
$placer = $base.'layers/Placer';
$stop = $base.'layers/JoPhi DEMOS/variants/stop';
$fps = 30;
$steps = 20;

setAnimateValue($placer, [
    'input-values' => [
        'tvGroup_Content__Opacity' => 0,
        'tvGroup_Geometry__Rotation' => -360,
    ]
], $steps, $fps);

setAnimateValue($source, [
    'input-values' => [
        'tvGroup_Content__Text_TypeMultiline' => 'World hello!',
        'tvGroup_Background__Color' => mimoColor('#b700ff'),
    ]
], $steps, $fps);

setSleep(2);

setAnimateValue($placer, [
    'input-values' => [
        'tvGroup_Content__Opacity' => 100,
        'tvGroup_Geometry__Rotation' => 360,
    ]
], $steps, $fps);

setAnimateValue($source, [
    'input-values' => [
        'tvGroup_Content__Text_TypeMultiline' => 'Hello World!',
        'tvGroup_Background__Color' => mimoColor('#19e42d'),
    ]
], $steps, $fps);

setSleep(0, false);
setLive($stop);