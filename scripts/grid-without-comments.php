<?php

$base = 'hosts/master/documents/autoGridTest';

// Test with window parameters: top, left, bottom, right
$result = setAutoGrid($base, '2%', '#FFFFFFFF', '#FF00FFFF', 30, 30, 30, 30);
setSleep(0.5);