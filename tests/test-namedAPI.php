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

// Include namedAPI functions
include('../functions/namedAPI.php');

// Build namedAPI
echo "Building namedAPI..." . PHP_EOL;
$namedAPI = buildNamedAPI();
echo "Done!" . PHP_EOL . PHP_EOL;

// Test 1: Show complete structure
echo "=== COMPLETE STRUCTURE ===" . PHP_EOL . PHP_EOL;

foreach ($namedAPI['documents'] as $docName => $doc) {
    echo "Document: {$docName}" . PHP_EOL;
    echo "  ID: {$doc['id']}" . PHP_EOL;
    echo "  Live State: {$doc['attributes']['live-state']}" . PHP_EOL . PHP_EOL;

    echo "  Layers:" . PHP_EOL;
    foreach ($doc['layers'] as $layerName => $layer) {
        echo "    - {$layerName} (ID: {$layer['id']})" . PHP_EOL;
        echo "      Live State: {$layer['attributes']['live-state']}" . PHP_EOL;

        if (!empty($layer['variants'])) {
            echo "      Variants:" . PHP_EOL;
            foreach ($layer['variants'] as $variantName => $variant) {
                echo "        * {$variantName} (ID: {$variant['id']})" . PHP_EOL;
            }
        }
    }
    echo PHP_EOL;

    echo "  Sources:" . PHP_EOL;
    foreach ($doc['sources'] as $sourceName => $source) {
        echo "    - {$sourceName} (ID: {$source['id']})" . PHP_EOL;

        if (!empty($source['filters'])) {
            echo "      Filters:" . PHP_EOL;
            foreach ($source['filters'] as $filterName => $filter) {
                echo "        * {$filterName} (ID: {$filter['id']})" . PHP_EOL;
            }
        }
    }
    echo PHP_EOL;

    echo "  Layer-Sets:" . PHP_EOL;
    foreach ($doc['layer-sets'] as $setName => $set) {
        echo "    - {$setName} (ID: {$set['id']})" . PHP_EOL;
    }
    echo PHP_EOL;

    echo "  Output-Destinations:" . PHP_EOL;
    foreach ($doc['output-destinations'] as $outputName => $output) {
        echo "    - {$outputName} (ID: {$output['id']})" . PHP_EOL;
        echo "      Type: {$output['attributes']['type']}" . PHP_EOL;
        echo "      Live State: {$output['attributes']['live-state']}" . PHP_EOL;
    }
    echo PHP_EOL;
}

// Test 2: Path resolution examples
echo PHP_EOL . "=== PATH RESOLUTION EXAMPLES ===" . PHP_EOL . PHP_EOL;

$testPaths = [
    'documents/forbiddenPHP',
    'documents/forbiddenPHP/layers/Comments',
    'documents/forbiddenPHP/layers/Comments/variants/Variant 1',
    'documents/forbiddenPHP/layers/SwitcherSwitch/variants/B',
    'documents/forbiddenPHP/sources/MacStudio',
    'documents/forbiddenPHP/sources/c1/filters/Color Correction',
    'documents/forbiddenPHP/layer-sets/RunA',
    'documents/forbiddenPHP/output-destinations/TW-stream',
];

foreach ($testPaths as $path) {
    echo "Named Path: {$path}" . PHP_EOL;

    $uuidPath = buildUUIDPath($path, $namedAPI);
    echo "UUID Path:  {$uuidPath}" . PHP_EOL;

    $resolved = resolveNamedPath($path, $namedAPI);
    echo "Resolved:   " . json_encode($resolved) . PHP_EOL;
    echo PHP_EOL;
}

// Test 3: Output JSON to stdout
echo PHP_EOL . "=== JSON OUTPUT ===" . PHP_EOL;
echo "To save to file, run: php test-namedAPI.php > namedAPI.json" . PHP_EOL . PHP_EOL;
echo json_encode($namedAPI, JSON_PRETTY_PRINT) . PHP_EOL;
