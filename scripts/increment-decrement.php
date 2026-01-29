<?php

$base = 'hosts/master/documents/forbiddenPHP/';
$placer = $base . 'layers/Placer';
$stop = $base . 'layers/JoPhi DEMOS/variants/stop';

// Increment audio, opacity and rotation
increment($placer, 'volume', 0.2);
increment($placer, 'tvGroup_Content__Opacity', 15);
increment($placer, 'tvGroup_Geometry__Rotation', 45);
setSleep(2);

// Decrement back
decrement($placer, 'volume', 0.2);
decrement($placer, 'tvGroup_Content__Opacity', 15);
decrement($placer, 'tvGroup_Geometry__Rotation', 45);
setSleep(1);

// End of script
setLive($base.'layers/JoPhi DEMOS/variants/stop');
