<?php
/**
 * test-fields.php
 *
 * Test queue fields with sleep
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

echo "=== QUEUE FIELDS TEST ===" . PHP_EOL . PHP_EOL;

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

echo "=== QUEUEING ACTIONS IN FIELDS ===" . PHP_EOL . PHP_EOL;

// Using base path variable
$master_base = 'hosts/master/documents/forbiddenPHP/';

// Field 1: Turn ON layers
echo "Field 1: Turn ON MEv and MEa" . PHP_EOL;
setLive($master_base . 'layers/MEv');
setLive($master_base . 'layers/MEa');

// Add 1.5 second sleep and start new field
echo "Sleep 1.5 seconds" . PHP_EOL;
setSleep(1.5);

// Field 2: Adjust volumes and turn OFF
echo "Field 2: Adjust volumes and turn layers OFF" . PHP_EOL;
setVolume($master_base . 'layers/MEa', 0.5);
setOff($master_base . 'layers/MEv');
setOff($master_base . 'layers/MEa');

// Add 0.5 second sleep
echo "Sleep 0.5 seconds (fraction test)" . PHP_EOL;
setSleep(0.5);

// Field 3: Turn layers back ON
echo "Field 3: Turn MEv back ON" . PHP_EOL;
setLive($master_base . 'layers/MEv');

echo PHP_EOL;

// Show queue structure
echo "=== QUEUE STRUCTURE ===" . PHP_EOL;
echo "Number of fields: " . count($GLOBALS['queue']) . PHP_EOL;
foreach ($GLOBALS['queue'] as $i => $field) {
    echo "Field " . ($i + 1) . ": " . count($field) . " items" . PHP_EOL;
}
echo PHP_EOL;

// Execute queue
echo "=== EXECUTING QUEUE ===" . PHP_EOL;
$results = executeQueue($namedAPI);

echo PHP_EOL;
echo "Total results: " . count($results) . PHP_EOL;
foreach ($results as $i => $result) {
    echo "  " . ($i + 1) . ". {$result['action']} on {$result['path']}: ";
    if (isset($result['result']['error'])) {
        echo "ERROR" . PHP_EOL;
    } else {
        echo "SUCCESS" . PHP_EOL;
    }
}

echo PHP_EOL . "Test completed!" . PHP_EOL;
