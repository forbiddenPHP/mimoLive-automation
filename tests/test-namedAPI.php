<?php
/**
 * test-namedAPI.php
 *
 * Demonstrates the namedAPI functionality
 */

// Session setup (same as index.php)
session_name("UMIMOLIVE");
session_id("01UMIMOSESSION10");
session_start();

// Include functions
include('../functions/multiCurlRequest.php');
include('../functions/namedAPI.php');

// Setup host configuration
$GLOBALS['hosts'] = ['master' => 'localhost'];
$GLOBALS['protocol'] = 'http';
$GLOBALS['port'] = 8989;

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

// Test 1: Show complete structure
echo "=== COMPLETE STRUCTURE ===" . PHP_EOL . PHP_EOL;

foreach ($namedAPI['hosts'] as $hostName => $host) {
    echo "Host: {$hostName}" . PHP_EOL . PHP_EOL;

    foreach ($host['documents'] as $docName => $doc) {
        echo "  Document: {$docName}" . PHP_EOL;
        echo "    ID: {$doc['id']}" . PHP_EOL;
        echo "    Live State: {$doc['attributes']['live-state']}" . PHP_EOL . PHP_EOL;

        echo "    Layers:" . PHP_EOL;
        foreach ($doc['layers'] as $layerName => $layer) {
            echo "      - {$layerName} (ID: {$layer['id']})" . PHP_EOL;
            echo "        Live State: {$layer['attributes']['live-state']}" . PHP_EOL;

            if (!empty($layer['variants'])) {
                echo "        Variants:" . PHP_EOL;
                foreach ($layer['variants'] as $variantName => $variant) {
                    echo "          * {$variantName} (ID: {$variant['id']})" . PHP_EOL;
                }
            }
        }
        echo PHP_EOL;

        echo "    Sources:" . PHP_EOL;
        foreach ($doc['sources'] as $sourceName => $source) {
            echo "      - {$sourceName} (ID: {$source['id']})" . PHP_EOL;

            if (!empty($source['filters'])) {
                echo "        Filters:" . PHP_EOL;
                foreach ($source['filters'] as $filterName => $filter) {
                    echo "          * {$filterName} (ID: {$filter['id']})" . PHP_EOL;
                }
            }
        }
        echo PHP_EOL;

        echo "    Layer-Sets:" . PHP_EOL;
        foreach ($doc['layer-sets'] as $setName => $set) {
            echo "      - {$setName} (ID: {$set['id']})" . PHP_EOL;
        }
        echo PHP_EOL;

        echo "    Output-Destinations:" . PHP_EOL;
        foreach ($doc['output-destinations'] as $outputName => $output) {
            echo "      - {$outputName} (ID: {$output['id']})" . PHP_EOL;
            echo "        Type: {$output['attributes']['type']}" . PHP_EOL;
            echo "        Live State: {$output['attributes']['live-state']}" . PHP_EOL;
        }
        echo PHP_EOL;
    }
}

// Test 2: Path resolution examples
echo PHP_EOL . "=== PATH RESOLUTION EXAMPLES ===" . PHP_EOL . PHP_EOL;

// Using base path variable (recommended approach)
$master_base = 'hosts/master/documents/forbiddenPHP/';

$testPaths = [
    $master_base . 'layers/Comments',
    $master_base . 'layers/Comments/variants/Variant 1',
    $master_base . 'layers/SwitcherSwitch/variants/B',
    $master_base . 'sources/MacStudio',
    $master_base . 'sources/c1/filters/Color Correction',
    $master_base . 'layer-sets/RunA',
    $master_base . 'output-destinations/TW-stream',
];

echo "Using base path variable: \$master_base = '{$master_base}'" . PHP_EOL . PHP_EOL;

foreach ($testPaths as $path) {
    echo "Named Path: {$path}" . PHP_EOL;

    $resolved = buildUUIDPath($path, $namedAPI);
    if ($resolved !== null) {
        echo "Host:       {$resolved['host']}" . PHP_EOL;
        echo "UUID Path:  {$resolved['path']}" . PHP_EOL;
    } else {
        echo "ERROR: Path not found" . PHP_EOL;
    }

    $resolvedDetails = resolveNamedPath($path, $namedAPI);
    echo "Resolved:   " . json_encode($resolvedDetails) . PHP_EOL;
    echo PHP_EOL;
}

// Test 3: Output JSON to stdout
echo PHP_EOL . "=== JSON OUTPUT ===" . PHP_EOL;
echo "To save to file, run: php test-namedAPI.php > namedAPI.json" . PHP_EOL . PHP_EOL;
echo json_encode($namedAPI, JSON_PRETTY_PRINT) . PHP_EOL;
