<?php
/**
 * test-queue.php
 *
 * Demonstrates the queue system for mimoLive automation
 */

// Initialize
$GLOBALS['queue'] = [[]];

// Include functions
include('../functions/multiCurlRequest.php');
include('../functions/namedAPI.php');
include('../functions/queue.php');

// Setup host configuration
$GLOBALS['hosts'] = ['master' => 'localhost'];
$GLOBALS['protocol'] = 'http';
$GLOBALS['port'] = 8989;

echo "=== QUEUE SYSTEM TEST ===" . PHP_EOL . PHP_EOL;

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
echo "Done!" . PHP_EOL . PHP_EOL;

// Example 1: Queue multiple actions
echo "=== Example 1: Queue Multiple Actions ===" . PHP_EOL . PHP_EOL;

// Using base path variable
$master_base = 'hosts/master/documents/forbiddenPHP/';

// Queue some actions
setLive($master_base . 'layers/Comments/variants/Variant 1');
setOff($master_base . 'layers/MEv');
setVolume($master_base . 'layers/Video Switcher', 0.75);

echo "Queued actions:" . PHP_EOL;
foreach ($GLOBALS['queue'][0] as $i => $item) {
    echo "  " . ($i + 1) . ". " . $item['action'] . " on " . $item['path'];
    if ($item['data'] !== null) {
        echo " (data: " . json_encode($item['data']) . ")";
    }
    echo PHP_EOL;
}
echo PHP_EOL;

// Execute queue (commented out - would actually call API)
// echo "Executing queue..." . PHP_EOL;
// $results = executeQueue($namedAPI);
// echo "Results:" . PHP_EOL;
// print_r($results);

echo "Queue cleared (would execute in production)" . PHP_EOL . PHP_EOL;
$GLOBALS['queue'] = []; // Clear for next example

// Example 2: Layer-Set recall
echo "=== Example 2: Layer-Set Recall ===" . PHP_EOL . PHP_EOL;

setLive($master_base . 'layer-sets/RunA');

echo "Queued action:" . PHP_EOL;
echo "  " . $GLOBALS['queue'][0][0]['action'] . " on " . $GLOBALS['queue'][0][0]['path'] . PHP_EOL;
echo "  (This will use /recall endpoint for layer-sets)" . PHP_EOL . PHP_EOL;

$GLOBALS['queue'] = [[]];

// Example 3: Variant cycling
echo "=== Example 3: Variant Cycling ===" . PHP_EOL . PHP_EOL;

cycleLayerVariantsForward($master_base . 'layers/SwitcherSwitch', false);
cycleLayerVariantsBackwards($master_base . 'layers/SwitcherSwitch', true);

echo "Queued actions:" . PHP_EOL;
foreach ($GLOBALS['queue'][0] as $i => $item) {
    echo "  " . ($i + 1) . ". " . $item['action'] . " on " . $item['path'];
    if (isset($item['data']['bounced'])) {
        echo " (bounced: " . ($item['data']['bounced'] ? 'yes' : 'no') . ")";
    }
    echo PHP_EOL;
}
echo PHP_EOL;

$GLOBALS['queue'] = [[]];

// Example 4: Complex workflow
echo "=== Example 4: Complex Workflow ===" . PHP_EOL . PHP_EOL;

// Activate layer set
setLive($master_base . 'layer-sets/RunB');

// Adjust volumes
setVolume($master_base . 'layers/Comments', 0.5);
setVolume($master_base . 'layers/Video Switcher', 0.8);

// Activate specific variant
setLive($master_base . 'layers/SwitcherSwitch/variants/C');

// Update attributes
updateAttributes($master_base . 'layers/Comments', [
    'name' => 'Comments Updated',
    'input-values' => [
        'someKey' => 'someValue'
    ]
]);

echo "Queued workflow (" . count($GLOBALS['queue'][0]) . " actions):" . PHP_EOL;
foreach ($GLOBALS['queue'][0] as $i => $item) {
    echo "  " . ($i + 1) . ". " . $item['action'] . " on " . $item['path'] . PHP_EOL;
}
echo PHP_EOL;

echo "In production, calling executeQueue(\$namedAPI) would:" . PHP_EOL;
echo "  1. Execute all actions via API" . PHP_EOL;
echo "  2. Update \$namedAPI with response data" . PHP_EOL;
echo "  3. Clear the queue" . PHP_EOL;
echo "  4. Return results" . PHP_EOL;
