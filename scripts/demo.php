<?php
    // script_start:
        $base='hosts/master/documents/forbiddenPHP/';
        setLive($base.'output-destinations/TV out');
        setValue($base.'sources/Color', ['input-values' => [
            'tvGroup_Content__Text_TypeMultiline'=> 'Start',
            'tvGroup_Background__Color' => mimoColor('#0')  // Green
        ]]);
        setVolume($base, 0);
        setVolume($base.'layers/MEa', 0);
        setLive($base.'layers/Comments');
        setLive($base.'layers/MEv');
        setLive($base.'layers/MEa');
        setVolume($base.'sources/a1',0);
    setSleep(2);
        setVolume($base.'layers/MEa', 1);
        setVolume($base.'sources/a1', 1);
        setVolume($base, 0);
    setSleep(0.5);
        setValue($base.'sources/Color', ['input-values' =>
            [
                'tvGroup_Content__Text_TypeMultiline'=> 'Hello',
                'tvGroup_Background__Color' => mimoColor('#FF0000'),  // Red
        ]]);
        setOff($base.'layers/Comments');
        setOff($base.'layers/MEv');
        setOff($base.'layers/MEa');
    butOnlyIf($base.'layers/MEa/attributes/tvGroup_Ducking__Enabled', '==', false);
        setVolume($base, 1);
    setSleep(1);
        setValue($base.'sources/Color', ['input-values' => [
            'tvGroup_Content__Text_TypeMultiline'=> 'Hello',
            'tvGroup_Background__Color' => mimoColor('#00FF00')  // Green
        ]]);
        recall($base.'layer-sets/RunA');
    setSleep(1);
        setValue($base.'sources/Color', ['input-values' => [
            'tvGroup_Content__Text_TypeMultiline'=> 'World',
            'tvGroup_Background__Color' => mimoColor('#0080FF')  // Blue
        ]]);
        recall($base.'layer-sets/RunB');
    setSleep(1);
        setValue($base.'sources/Color', ['input-values' => [
            'tvGroup_Content__Text_TypeMultiline'=> 'Hello',
            'tvGroup_Background__Color' => mimoColor('#FFD700')  // Gold
        ]]);
        recall($base.'layer-sets/RunC');
    setSleep(1);
        setValue($base.'sources/Color', ['input-values' => [
            'tvGroup_Content__Text_TypeMultiline'=> 'World',
            'tvGroup_Background__Color' => mimoColor('#FF00FF')  // Magenta
        ]]);
        recall($base.'layer-sets/OFF');
    setSleep(0);
        setOff($base.'output-destinations/TV out');
        setLive($base.'layers/JoPhi DEMOS/variants/stop');
