<?php 
    $base_path='hosts/master/documents/forbiddenPHP/';

    for ($i=0; $i<2; ++$i) {
        setSleep(2);
            setOff($base_path.'layers/MEv');
            setVolume($base_path.'layers/MEv', 0);
            setOff($base_path.'layers/MEa');
            setVolume($base_path.'layers/MEa', 0);
        setSleep(2);
            setLive($base_path.'layers/MEv');
            setLive($base_path.'layers/MEa');
            setVolume($base_path.'layers/MEa', 1);
            setVolume($base_path.'layers/MEv', 0);
    }
    setSleep(2);
        setLive($base_path.'layers/RunAndStop/variants/stop');