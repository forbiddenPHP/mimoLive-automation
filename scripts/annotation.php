<?php
$base = 'hosts/master/documents/annotation/';
$layer = $base . 'layers/testlayer';
$done = $base . 'layers/Automation/variants/done';

// Test 1a: Large text, small box
setAndAdjustAnnotationText($layer, 'Subscribe to my channel!', 270, 690, 540, 540, 5);
setSleep(2, true); // reload current values, so "true"

// Test 2b: use the already existing text
setAndAdjustAnnotationText($layer, '', 100, 50, 300, 880, 5);
setSleep(2, false);

// Test 2: Long text, wide flat box
setAndAdjustAnnotationText($layer, 'This is a much longer text that should automatically wrap and shrink to fit!', 800, 100, 1720, 200, 5);
setSleep(2, false);

// Test 3: Short text, almost fullscreen
setAndAdjustAnnotationText($layer, 'WOW', 50, 50, 1820, 980, 10);
setSleep(2, false);

// Test 4: Single word, narrow tall box
setAndAdjustAnnotationText($layer, 'Hello!', 100, 50, 300, 880, 5);
setSleep(2, false);

// Test 5: Lots of text, medium box
setAndAdjustAnnotationText($layer, 'The quick brown fox jumps over the lazy dog while the sun sets behind the mountains on a warm summer evening.', 300, 400, 1100, 500, 8);
setSleep(2, false);

// Test 6: Emoji test, small box bottom right (contains non-breaking space! On MacOS: option+space-key)
setAndAdjustAnnotationText($layer, '🎬 LIVE NOW 🎬', 850, 1400, 480, 180, 10);
setSleep(2, false);

// Test 7: Single line, full width, flat
setAndAdjustAnnotationText($layer, 'BREAKING NEWS: Something incredible just happened!', 980, 50, 1820, 80, 5);
setSleep(2, false);

// Test 8: Tiny box
setAndAdjustAnnotationText($layer, 'Hi', 500, 900, 120, 80, 5);
setSleep(2, false);

// Test 9: Multi-line forced by narrow box
setAndAdjustAnnotationText($layer, 'Welcome to the livestream this evening!', 200, 1500, 370, 680, 5);
setSleep(2, false);

// Test 10: Fullscreen title
setAndAdjustAnnotationText($layer, 'THE GRAND FINALE', 0, 0, 1920, 1080, 15);
setSleep(2, false);

setLive($done);