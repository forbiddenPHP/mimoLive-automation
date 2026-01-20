<?php
    // Simple test script
    $base_path = 'hosts/master/documents/forbiddenPHP/';

    // Turn layer ON
    setLive($base_path . 'layers/MEv');

    // Wait 2 seconds
    setSleep(2);

    // Turn layer OFF
    setOff($base_path . 'layers/MEv');
