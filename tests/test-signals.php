<?php
/**
 * test-signals.php
 *
 * Test signal triggering functionality
 */

// Initialize
$GLOBALS['queue'] = [[]];

// Include functions
include('../functions/setter-getter.php');
include('../functions/namedAPI.php');
include('../functions/queue.php');
include('../functions/multiCurlRequest.php');

echo "=== SIGNAL TRIGGER TEST ===" . PHP_EOL . PHP_EOL;

// Build namedAPI
echo "Building namedAPI..." . PHP_EOL;
$namedAPI = buildNamedAPI();
echo "Done!" . PHP_EOL . PHP_EOL;

// Show available signals on Video Switcher layer
echo "=== AVAILABLE SIGNALS ON VIDEO SWITCHER ===" . PHP_EOL;
$signals = array_get($namedAPI, 'documents/forbiddenPHP/layers/Video Switcher/signals');

if ($signals) {
    foreach ($signals as $normalizedName => $signalData) {
        echo "  {$normalizedName} -> {$signalData['display-name']} ({$signalData['real-signal-id']})" . PHP_EOL;
    }
} else {
    echo "  No signals found!" . PHP_EOL;
}

echo PHP_EOL;

// Test triggering a signal
echo "=== TESTING SIGNAL TRIGGER ===" . PHP_EOL;
echo "Triggering signal 'Cut 1' on Video Switcher..." . PHP_EOL;
triggerSignal('Cut 1', 'documents/forbiddenPHP/layers/Video Switcher');

echo "Executing queue..." . PHP_EOL;
$results = executeQueue($namedAPI);

echo PHP_EOL;

// Show results
echo "=== RESULTS ===" . PHP_EOL;
foreach ($results as $i => $result) {
    echo ($i + 1) . ". {$result['action']} on {$result['path']}:" . PHP_EOL;
    if (isset($result['result']['error'])) {
        echo "   ERROR: {$result['result']['error']}" . PHP_EOL;
    } else {
        echo "   SUCCESS" . PHP_EOL;
    }
}

echo PHP_EOL . "Test completed!" . PHP_EOL;
