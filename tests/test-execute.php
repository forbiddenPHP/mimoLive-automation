<?php
/**
 * test-execute.php
 *
 * Test actual execution of queue actions
 */

// Initialize
$GLOBALS['queue'] = [];

// Include functions
include('../functions/namedAPI.php');
include('../functions/queue.php');
include('../functions/multiCurlRequest.php');

echo "=== QUEUE EXECUTION TEST ===" . PHP_EOL . PHP_EOL;

// Build namedAPI
echo "Building namedAPI..." . PHP_EOL;
$namedAPI = buildNamedAPI();
echo "Done!" . PHP_EOL . PHP_EOL;

// Show current state
echo "=== CURRENT LAYER STATES ===" . PHP_EOL;
foreach ($namedAPI['documents']['forbiddenPHP']['layers'] as $layerName => $layer) {
    echo "  {$layerName}: {$layer['attributes']['live-state']}" . PHP_EOL;
}
echo PHP_EOL;

// Queue some actions
echo "=== QUEUEING ACTIONS ===" . PHP_EOL;

// Turn ON MEv
echo "1. Turning ON MEv" . PHP_EOL;
setLive('documents/forbiddenPHP/layers/MEv');

// Turn ON MEa
echo "2. Turning ON MEa" . PHP_EOL;
setLive('documents/forbiddenPHP/layers/MEa');

// Set volume on MEa (has volume support)
echo "3. Setting MEa volume to 0.6" . PHP_EOL;
setVolume('documents/forbiddenPHP/layers/MEa', 0.6);

// Cycle SwitcherSwitch variants
echo "4. Cycling SwitcherSwitch to next variant" . PHP_EOL;
cycleLayerVariantsForward('documents/forbiddenPHP/layers/SwitcherSwitch', false);

echo PHP_EOL;

// Execute queue
echo "=== EXECUTING QUEUE ===" . PHP_EOL;
$results = executeQueue($namedAPI);

// Show results
echo "Results:" . PHP_EOL;
foreach ($results as $i => $result) {
    echo "  " . ($i + 1) . ". {$result['action']} on {$result['path']}: ";
    if (isset($result['result']['error'])) {
        echo "ERROR - {$result['result']['error']}";
        if (isset($result['result']['status'])) {
            echo " (HTTP {$result['result']['status']})";
        }
        if (isset($result['result']['curl_error'])) {
            echo " - cURL: {$result['result']['curl_error']}";
        }
        echo PHP_EOL;
    } else {
        echo "SUCCESS" . PHP_EOL;
    }
}
echo PHP_EOL;

// Show updated state
echo "=== UPDATED LAYER STATES ===" . PHP_EOL;
foreach ($namedAPI['documents']['forbiddenPHP']['layers'] as $layerName => $layer) {
    echo "  {$layerName}: {$layer['attributes']['live-state']}" . PHP_EOL;
}
echo PHP_EOL;

echo "Test completed!" . PHP_EOL;
