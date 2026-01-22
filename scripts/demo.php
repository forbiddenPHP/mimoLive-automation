<?php
    // this is a demo
    $base='hosts/master/documents/forbiddenPHP/';
    setLive($base.'layers/Comments');
    setLive($base.'layers/MEv');
    setLive($base.'layers/MEa');
    setSleep(2);
    setOff($base.'layers/Comments');
    setOff($base.'layers/MEv');
    setOff($base.'layers/MEa');
    setSleep(1);
    recall($base.'layer-sets/RunA');
    setSleep(1);
    recall($base.'layer-sets/RunB');
    setSleep(1);
    recall($base.'layer-sets/RunC');
    setSleep(1);
    recall($base.'layer-sets/OFF');
    setLive($base.'layers/JoPhi DEMOS/variants/stop');
    