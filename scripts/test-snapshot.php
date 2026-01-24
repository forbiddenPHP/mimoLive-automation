<?php

$base = 'hosts/master/documents/forbiddenPHP/';

// Test 1: Program Output Snapshot mit Defaults (aus metadata)
snapshot($base);

setSleep(1);

// Test 2: Program Output mit custom Dimensionen und Format
snapshot($base, 1280, 720, 'jpeg');

setSleep(1);

// Test 3: Source Preview Snapshot (ändere den Source-Namen zu einer bei dir vorhandenen Source)
snapshot($base.'sources/Color');

setSleep(1);

// Test 4: Custom Filepath
snapshot($base, 1920, 1080, 'png', './snapshots/custom-test.png');

setSleep(1);

// Test 5: Transparent source as PNG
snapshot($base.'sources/c1-with-bluescreen', 1920, 1080, 'png');

setSleep(1);
setLive($base.'layers/JoPhi DEMOS/variants/stop');