<?php

$base = 'hosts/master/documents/forbiddenPHP/';
$layers = $base . 'layers/';

// Animate volume from current to 1.0 over 10 frames
setAnimateVolume($layers.'MEv', 1.0, 30, 30);
setAnimateVolume($layers.'MEa', 1.0, 30, 30);
setSleep(0);
setAnimateVolume($layers.'MEv', 0.0, 30, 60);
setAnimateVolume($layers.'MEa', 0.0, 30, 60);
setSleep(0);
setAnimateVolume($layers.'MEv', 0.5, 30, 120);
setAnimateVolume($layers.'MEa', 0.5, 30, 120);
setSleep(0);
setAnimateVolume($layers.'MEv', 0.0, 30, 15);
setAnimateVolume($layers.'MEa', 0.0, 30, 15);
setSleep(0);
setLive($layers.'JoPhi DEMOS/variants/stop');