<?php

// Replace this with a real document or open autoGridTest for this.
$base = 'hosts/master/documents/autoGridTest';
$test = [];
// Test with window parameters: top, left, bottom, right

$test['document']=isLive($base);
$test['layer triggers']=isLive($base.'/layers/triggers');
$test['variant 100%']=isLive($base.'/layers/triggers/variants/100%');
$test['layer-set reset']=isLive($base.'/layer-sets/reset');
$test['output-destination o_BMD']=isLive($base.'/output-destinations/o_BMD');

foreach($test as $k => $v) {
    if      ($v===null)     $v ='null';
    elseif  ($v===true)     $v ='true';
    elseif  ($v===false)    $v= 'false';
    debug_print('script-debug', $k.': '.$v);
}
