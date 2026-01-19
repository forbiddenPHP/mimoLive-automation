<?php
/**
 * test-gain.php
 *
 * Test gain setting for sources
 */

// Initialize
$GLOBALS['queue'] = [];

// Include functions
include('../functions/namedAPI.php');
include('../functions/queue.php');
include('../functions/multiCurlRequest.php');

echo "=== SOURCE GAIN TEST ===" . PHP_EOL . PHP_EOL;

// Build namedAPI
echo "Building namedAPI..." . PHP_EOL;
$namedAPI = buildNamedAPI();
echo "Done!" . PHP_EOL . PHP_EOL;

// Show available sources
echo "=== AVAILABLE SOURCES ===" . PHP_EOL;
foreach ($namedAPI['documents']['forbiddenPHP']['sources'] as $sourceName => $source) {
    $gain = $source['attributes']['gain'] ?? 'null';
    echo "  {$sourceName}: gain={$gain}" . PHP_EOL;
}
echo PHP_EOL;

// Queue gain adjustments
echo "=== QUEUEING ACTIONS ===" . PHP_EOL;

echo "1. Setting a1 gain to 1.5" . PHP_EOL;
setGain('documents/forbiddenPHP/sources/a1', 1.5);

echo "2. Setting c1 gain to 0.8" . PHP_EOL;
setGain('documents/forbiddenPHP/sources/c1', 0.8);

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
        echo PHP_EOL;
    } else {
        echo "SUCCESS" . PHP_EOL;
    }
}
echo PHP_EOL;

echo "Test completed!" . PHP_EOL;
