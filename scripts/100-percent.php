<?php

$base = 'hosts/master/documents/autoGridTest';

// Test with window parameters: top, left, bottom, right
$result = setAutoGrid($base, 0, '#FFFFFFFF', '#FF00FFFF', 0, 0, 0, 0);
setSleep(0.5);