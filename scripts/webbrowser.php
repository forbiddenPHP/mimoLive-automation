<?php
$base='hosts/master/documents/forbiddenPHP/';

// Set the URL to mimolive.com
setValue($base.'sources/Web Browser', ['website-url' => 'https://mimolive.com']);
setSleep(1);
// Open the browser
openWebBrowser($base.'sources/Web Browser');
setSleep(1);
// Plot tiwst: change the url QUICKLY to my github page
setValue($base.'sources/Web Browser', ['website-url' => 'https://github.com/forbiddenPHP/mimoLive-automation']);
setSleep(0.5);
setLive($base.'layers/JoPhi DEMOS/variants/stop');