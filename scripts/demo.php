<?php 
    $base_path='hosts/master/documents/forbiddenPHP/';

    for ($i=0; $i<2; ++$i) {
        setSleep(2);
            setOff($base_path.'layers/MEv');
            setAnimateVolumeTo($base_path.'layers/MEv', 0, 15);
            setOff($base_path.'layers/MEa');
            setAnimateVolumeTo($base_path.'layers/MEa', 0, 15);
        setSleep(2);
            setLive($base_path.'layers/MEv');
            setAnimateVolumeTo($base_path.'layers/MEv', 1, 15);
            setLive($base_path.'layers/MEa');
            setAnimateVolumeTo($base_path.'layers/MEa', 1, 15);
            setVolume($base_path.'layers/MEv', 0);
    }
    setSleep(2);
        setLive($base_path.'layers/RunAndStop/variants/stop');