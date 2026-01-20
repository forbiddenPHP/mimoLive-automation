<?php
/**
 * test-multi-host.php
 *
 * Demonstrates multi-host usage with path variables
 */

// Initialize
$GLOBALS['queue'] = [[]];

// Include functions
include('../functions/multiCurlRequest.php');
include('../functions/namedAPI.php');
include('../functions/queue.php');

// Setup host configuration
$GLOBALS['hosts'] = ['master' => 'localhost', 'backup' => 'macstudio-von-jophi.local'];
$GLOBALS['protocol'] = 'http';
$GLOBALS['port'] = 8989;

echo "=== MULTI-HOST DEMONSTRATION ===" . PHP_EOL . PHP_EOL;

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

// Show host availability
echo PHP_EOL . "=== HOST AVAILABILITY ===" . PHP_EOL;
foreach ($hostsData as $hostName => $response) {
    $status = $response['status'] ?? 0;
    if ($status == 200) {
        echo "  ✓ {$hostName}: Available" . PHP_EOL;
    } else {
        echo "  ✗ {$hostName}: Unavailable (HTTP {$status})" . PHP_EOL;
    }
}
echo PHP_EOL;

// Build namedAPI
echo "Building namedAPI..." . PHP_EOL;
$namedAPI = buildNamedAPI(null, $hostsData);
echo "Done!" . PHP_EOL . PHP_EOL;

// Define base paths for each host (RECOMMENDED PATTERN)
$master_base = 'hosts/master/documents/forbiddenPHP/';
$backup_base = 'hosts/backup/documents/forbiddenPHP/';

// Show available documents per host
echo "=== AVAILABLE DOCUMENTS ===" . PHP_EOL;
foreach ($namedAPI['hosts'] as $hostName => $host) {
    echo "Host: {$hostName}" . PHP_EOL;
    foreach ($host['documents'] as $docName => $doc) {
        echo "  - Document: {$docName}" . PHP_EOL;
        echo "    Layers: " . count($doc['layers']) . PHP_EOL;
        echo "    Sources: " . count($doc['sources']) . PHP_EOL;
    }
    echo PHP_EOL;
}

// Example: Queue actions on both hosts
echo "=== QUEUEING ACTIONS ON MULTIPLE HOSTS ===" . PHP_EOL;

// Master host actions
echo "Master host:" . PHP_EOL;
echo "  - Turn MEv ON" . PHP_EOL;
setLive($master_base . 'layers/MEv');

echo "  - Turn MEa ON" . PHP_EOL;
setLive($master_base . 'layers/MEa');

// Backup host actions (if available)
if (isset($namedAPI['hosts']['backup']) && !empty($namedAPI['hosts']['backup']['documents'])) {
    echo "Backup host:" . PHP_EOL;
    echo "  - Turn MEv ON" . PHP_EOL;
    setLive($backup_base . 'layers/MEv');

    echo "  - Turn MEa ON" . PHP_EOL;
    setLive($backup_base . 'layers/MEa');
} else {
    echo "Backup host: Not available, skipping..." . PHP_EOL;
}

echo PHP_EOL;

// Show queued actions
echo "=== QUEUED ACTIONS ===" . PHP_EOL;
echo "Total actions: " . count($GLOBALS['queue'][0]) . PHP_EOL;
foreach ($GLOBALS['queue'][0] as $i => $item) {
    echo "  " . ($i + 1) . ". {$item['action']} on {$item['path']}" . PHP_EOL;
}
echo PHP_EOL;

// Execute queue (commented out - would actually call API)
// echo "=== EXECUTING QUEUE ===" . PHP_EOL;
// $results = executeQueue($namedAPI);
// foreach ($results as $i => $result) {
//     echo "  " . ($i + 1) . ". {$result['action']} on {$result['path']}: ";
//     if (isset($result['result']['error'])) {
//         echo "ERROR" . PHP_EOL;
//     } else {
//         echo "SUCCESS" . PHP_EOL;
//     }
// }

echo "In production, these actions would execute on their respective hosts." . PHP_EOL;
echo PHP_EOL . "Test completed!" . PHP_EOL;
