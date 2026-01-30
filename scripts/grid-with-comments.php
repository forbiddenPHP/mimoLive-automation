<?php

$base = 'hosts/master/documents/autoGridTest';

// Test with window parameters: top, left, bottom, right
$result = setAutoGrid($base, '2%', '#FFFFFFFF', '#FF00FFFF', 300, 30, 30, 30);
setSleep(0, false);