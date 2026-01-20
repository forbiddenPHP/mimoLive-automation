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
include('../functions/multiCurlRequest.php');
include('../functions/namedAPI.php');
include('../functions/queue.php');

// Setup host configuration
$GLOBALS['hosts'] = ['master' => 'localhost'];
$GLOBALS['protocol'] = 'http';
$GLOBALS['port'] = 8989;
$GLOBALS['namedAPI'] = [];

echo "=== SIGNAL TRIGGER TEST ===" . PHP_EOL . PHP_EOL;

// Fetch documents from all hosts
echo "Fetching documents from hosts..." . PHP_EOL;
$requests = [];
foreach ($GLOBALS['hosts'] as $hostName => $hostAddress) {
    $url = $GLOBALS['protocol'] . '://' . $hostAddress . ':' . $GLOBALS['port'] . '/api/v1/documents';
    $requests[$hostName] = [
        'url'    => $url,
        'method' => 'GET',
    ];
}
$hostsData = multiCurlRequest($requests);

// Build namedAPI
echo "Building namedAPI..." . PHP_EOL;
$namedAPI = buildNamedAPI(null, $hostsData);
$GLOBALS['namedAPI'] = $namedAPI;
echo "Done!" . PHP_EOL . PHP_EOL;

// Using base path variable
$master_base = 'hosts/master/documents/forbiddenPHP/';

// Show available signals on Video Switcher layer
echo "=== AVAILABLE SIGNALS ON VIDEO SWITCHER ===" . PHP_EOL;
$signals = memo_get($master_base . 'layers/Video Switcher/signals');

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
triggerSignal('Cut 1', $master_base . 'layers/Video Switcher');

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
