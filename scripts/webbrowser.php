<?php
$base='hosts/master/documents/forbiddenPHP/';

// Set the URL to claude.ai
setValue($base.'sources/Web Browser', ['website-url' => 'https://mimolive.com']);
setSleep(1);
// Open the browser
openWebBrowser($base.'sources/Web Browser');
setSleep(1);
setValue($base.'sources/Web Browser', ['website-url' => 'https://github.com/forbiddenPHP/mimoLive-automation']);
setSleep(0.5);
setLive($base.'layers/JoPhi DEMOS/variants/stop');