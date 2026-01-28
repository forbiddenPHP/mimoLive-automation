<?php

$base = 'hosts/master/documents/forbiddenPHP/sources/Color/';

// Test 1: Animate a number (Shadow Direction - wheel 0-360°)
setAnimateValue($base, [
    'input-values' => [
        'tvGroup_Appearance__Shadow_Direction' => 180
    ]
], 30, 30);

setSleep(1);

// Test 2: Animate back (should take shortest path)
setAnimateValue($base, [
    'input-values' => [
        'tvGroup_Appearance__Shadow_Direction' => 0
    ]
], 30, 30);

setSleep(1);

// Test 3: Animate a color
setAnimateValue($base, [
    'input-values' => [
        'tvGroup_Appearance__Shadow_Color' => ['red' => 1.0, 'green' => 0.0, 'blue' => 0.0, 'alpha' => 1.0]
    ]
], 30, 30);

setSleep(1);

// Test 4: Animate color back
setAnimateValue($base, [
    'input-values' => [
        'tvGroup_Appearance__Shadow_Color' => ['red' => 0.0, 'green' => 0.0, 'blue' => 0.0, 'alpha' => 0.32]
    ]
], 30, 30);

setSleep(0);
