<?php
/**
 * test-optimization.php
 *
 * Test that script analysis correctly identifies what needs to be loaded
 */

// Include functions
include('../functions/analyzeScriptNeeds.php');

echo "=== SCRIPT ANALYSIS OPTIMIZATION TEST ===" . PHP_EOL . PHP_EOL;

// Test scripts
$testScripts = [
    // Test 1: Only layers
    [
        'script' => "setLive('documents/forbiddenPHP/layers/MEv');\nsetOff('documents/forbiddenPHP/layers/MEa');",
        'expected' => [
            'layers' => true,
            'layers_variants' => false,
            'layers_signals' => false,
            'sources' => false,
            'sources_filters' => false,
            'sources_signals' => false,
            'layer-sets' => false,
            'output-destinations' => false,
        ]
    ],

    // Test 2: Layers with variants
    [
        'script' => "setLive('documents/forbiddenPHP/layers/Comments/variants/Variant 1');",
        'expected' => [
            'layers' => true,
            'layers_variants' => true,
            'layers_signals' => false,
            'sources' => false,
            'sources_filters' => false,
            'sources_signals' => false,
            'layer-sets' => false,
            'output-destinations' => false,
        ]
    ],

    // Test 3: Layers with signals
    [
        'script' => "triggerSignal('Cut 1', 'documents/forbiddenPHP/layers/Video Switcher');",
        'expected' => [
            'layers' => true,
            'layers_variants' => false,
            'layers_signals' => true,
            'sources' => false,
            'sources_filters' => false,
            'sources_signals' => false,
            'layer-sets' => false,
            'output-destinations' => false,
        ]
    ],

    // Test 4: Sources only
    [
        'script' => "setLive('documents/forbiddenPHP/sources/MacStudio');",
        'expected' => [
            'layers' => false,
            'layers_variants' => false,
            'layers_signals' => false,
            'sources' => true,
            'sources_filters' => false,
            'sources_signals' => false,
            'layer-sets' => false,
            'output-destinations' => false,
        ]
    ],

    // Test 5: Sources with filters
    [
        'script' => "setLive('documents/forbiddenPHP/sources/c1/filters/Color Correction');",
        'expected' => [
            'layers' => false,
            'layers_variants' => false,
            'layers_signals' => false,
            'sources' => true,
            'sources_filters' => true,
            'sources_signals' => false,
            'layer-sets' => false,
            'output-destinations' => false,
        ]
    ],

    // Test 6: Layer-sets
    [
        'script' => "setLive('documents/forbiddenPHP/layer-sets/RunA');",
        'expected' => [
            'layers' => false,
            'layers_variants' => false,
            'layers_signals' => false,
            'sources' => false,
            'sources_filters' => false,
            'sources_signals' => false,
            'layer-sets' => true,
            'output-destinations' => false,
        ]
    ],

    // Test 7: Output destinations
    [
        'script' => "setLive('documents/forbiddenPHP/output-destinations/TW-stream');",
        'expected' => [
            'layers' => false,
            'layers_variants' => false,
            'layers_signals' => false,
            'sources' => false,
            'sources_filters' => false,
            'sources_signals' => false,
            'layer-sets' => false,
            'output-destinations' => true,
        ]
    ],

    // Test 8: Complex script with multiple types
    [
        'script' => "setLive('documents/forbiddenPHP/layers/MEv');\n" .
                   "setLive('documents/forbiddenPHP/layers/Comments/variants/Variant 1');\n" .
                   "triggerSignal('Cut 1', 'documents/forbiddenPHP/layers/Video Switcher');\n" .
                   "setLive('documents/forbiddenPHP/sources/c1/filters/Color Correction');\n" .
                   "setLive('documents/forbiddenPHP/layer-sets/RunA');",
        'expected' => [
            'layers' => true,
            'layers_variants' => true,
            'layers_signals' => true,
            'sources' => true,
            'sources_filters' => true,
            'sources_signals' => false,
            'layer-sets' => true,
            'output-destinations' => false,
        ]
    ],

    // Test 9: Early exit optimization (variants found first)
    [
        'script' => "setLive('documents/forbiddenPHP/layers/Comments/variants/Variant 1');\n" .
                   "setLive('documents/forbiddenPHP/layers/MEv');\n" .
                   "setLive('documents/forbiddenPHP/layers/MEa');",
        'expected' => [
            'layers' => true,
            'layers_variants' => true,
            'layers_signals' => false,
            'sources' => false,
            'sources_filters' => false,
            'sources_signals' => false,
            'layer-sets' => false,
            'output-destinations' => false,
        ]
    ],
];

$allPassed = true;

foreach ($testScripts as $index => $test) {
    $testNum = $index + 1;
    echo "Test {$testNum}:" . PHP_EOL;
    echo "Script: " . str_replace("\n", " ", substr($test['script'], 0, 80)) . (strlen($test['script']) > 80 ? '...' : '') . PHP_EOL;

    $result = analyzeScriptNeeds($test['script']);

    $testPassed = true;
    foreach ($test['expected'] as $key => $expectedValue) {
        if ($result[$key] !== $expectedValue) {
            echo "  ✗ FAILED: {$key} expected " . ($expectedValue ? 'true' : 'false') . ", got " . ($result[$key] ? 'true' : 'false') . PHP_EOL;
            $testPassed = false;
            $allPassed = false;
        }
    }

    if ($testPassed) {
        echo "  ✓ PASSED" . PHP_EOL;
    }

    echo PHP_EOL;
}

// Final result
if ($allPassed) {
    echo "✓✓✓ ALL TESTS PASSED! Script analysis working correctly." . PHP_EOL;
} else {
    echo "✗✗✗ SOME TESTS FAILED! Check script analysis logic." . PHP_EOL;
}

echo PHP_EOL . "Test completed!" . PHP_EOL;
