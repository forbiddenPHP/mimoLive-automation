<?php
/**
 * test-namedAPI-update.php
 *
 * Test that namedAPI is correctly updated after queue execution
 */

// Initialize
$GLOBALS['queue'] = [[]];

// Include functions
include('../functions/setter-getter.php');
include('../functions/namedAPI.php');
include('../functions/queue.php');
include('../functions/multiCurlRequest.php');

echo "=== NAMEDAPI UPDATE TEST ===" . PHP_EOL . PHP_EOL;

// Build namedAPI
echo "Building namedAPI..." . PHP_EOL;
$namedAPI = buildNamedAPI();
echo "Done!" . PHP_EOL . PHP_EOL;

// Get initial states
echo "=== INITIAL STATES ===" . PHP_EOL;
$mevInitial = array_get($namedAPI, 'documents/forbiddenPHP/layers/MEv/attributes/live-state');
$meaInitial = array_get($namedAPI, 'documents/forbiddenPHP/layers/MEa/attributes/live-state');
$meaVolumeInitial = array_get($namedAPI, 'documents/forbiddenPHP/layers/MEa/attributes/volume');

echo "MEv liveState: {$mevInitial}" . PHP_EOL;
echo "MEa liveState: {$meaInitial}" . PHP_EOL;
echo "MEa volume: {$meaVolumeInitial}" . PHP_EOL;
echo PHP_EOL;

// Queue actions
echo "=== QUEUEING ACTIONS ===" . PHP_EOL;
echo "1. Turn MEv ON" . PHP_EOL;
setLive('documents/forbiddenPHP/layers/MEv');

echo "2. Turn MEa ON" . PHP_EOL;
setLive('documents/forbiddenPHP/layers/MEa');

echo "3. Set MEa volume to 0.7" . PHP_EOL;
setVolume('documents/forbiddenPHP/layers/MEa', 0.7);

echo "4. Turn MEv OFF" . PHP_EOL;
setOff('documents/forbiddenPHP/layers/MEv');

echo PHP_EOL;

// Execute queue
echo "=== EXECUTING QUEUE ===" . PHP_EOL;
$results = executeQueue($namedAPI);
echo PHP_EOL;

// Show API responses
echo "=== API RESPONSES ===" . PHP_EOL;
foreach ($results as $i => $result) {
    echo ($i + 1) . ". {$result['action']} on {$result['path']}:" . PHP_EOL;
    if (isset($result['result']['data']['attributes'])) {
        echo "   Attributes returned: " . json_encode($result['result']['data']['attributes']) . PHP_EOL;
    } else {
        echo "   No attributes in response!" . PHP_EOL;
    }
}
echo PHP_EOL;

// Get updated states
echo "=== UPDATED STATES (from namedAPI) ===" . PHP_EOL;
$mevUpdated = array_get($namedAPI, 'documents/forbiddenPHP/layers/MEv/attributes/live-state');
$meaUpdated = array_get($namedAPI, 'documents/forbiddenPHP/layers/MEa/attributes/live-state');
$meaVolumeUpdated = array_get($namedAPI, 'documents/forbiddenPHP/layers/MEa/attributes/volume');

echo "MEv liveState: {$mevUpdated}" . PHP_EOL;
echo "MEa liveState: {$meaUpdated}" . PHP_EOL;
echo "MEa volume: {$meaVolumeUpdated}" . PHP_EOL;
echo PHP_EOL;

// Verify changes
echo "=== VERIFICATION ===" . PHP_EOL;
$allCorrect = true;

// Check MEv: should be OFF or shutdown (action 4)
if ($mevUpdated === 'off' || $mevUpdated === 'shutdown') {
    echo "✓ MEv is OFF/shutdown (correct)" . PHP_EOL;
} else {
    echo "✗ MEv is {$mevUpdated}, expected 'off' or 'shutdown'" . PHP_EOL;
    $allCorrect = false;
}

// Check MEa: should be LIVE (action 2)
if ($meaUpdated === 'live') {
    echo "✓ MEa is LIVE (correct)" . PHP_EOL;
} else {
    echo "✗ MEa is {$meaUpdated}, expected 'live'" . PHP_EOL;
    $allCorrect = false;
}

// Check MEa volume: should be 0.7 (action 3)
if (abs($meaVolumeUpdated - 0.7) < 0.01) {
    echo "✓ MEa volume is 0.7 (correct)" . PHP_EOL;
} else {
    echo "✗ MEa volume is {$meaVolumeUpdated}, expected 0.7" . PHP_EOL;
    $allCorrect = false;
}

echo PHP_EOL;

// Final result
if ($allCorrect) {
    echo "✓✓✓ ALL TESTS PASSED! namedAPI is correctly updated." . PHP_EOL;
} else {
    echo "✗✗✗ SOME TESTS FAILED! namedAPI update has issues." . PHP_EOL;
}

echo PHP_EOL . "Test completed!" . PHP_EOL;
