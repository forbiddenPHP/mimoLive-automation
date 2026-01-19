<?php
/**
 * analyzeScriptNeeds.php
 *
 * Analyzes the script string to determine what parts of the API need to be loaded
 * Uses optimization flags to avoid redundant checks
 */

function analyzeScriptNeeds($script) {
    $needs = [
        'layers' => false,
        'layers_variants' => false,
        'layers_signals' => false,
        'sources' => false,
        'sources_filters' => false,
        'sources_signals' => false,
        'layer-sets' => false,
        'output-destinations' => false,
    ];

    // Optimization flags - once found, don't check again
    $checks = [
        'check_layers' => true,
        'check_variants' => true,
        'check_signals' => true,
        'check_sources' => true,
        'check_filters' => true,
        'check_layer_sets' => true,
        'check_outputs' => true,
    ];

    // Split script into lines for sequential analysis
    $lines = explode("\n", $script);

    foreach ($lines as $line) {
        // Check for layers
        if ($checks['check_layers'] && str_contains($line, '/layers/')) {
            $needs['layers'] = true;
            $checks['check_layers'] = false;
        }

        // Check for variants (requires layers)
        if ($checks['check_variants'] && str_contains($line, '/variants/')) {
            $needs['layers'] = true;
            $needs['layers_variants'] = true;
            $checks['check_variants'] = false;
            $checks['check_layers'] = false;
        }

        // Check for signals on layers (requires layers)
        if ($checks['check_signals'] && str_contains($line, 'triggerSignal') && str_contains($line, '/layers/')) {
            $needs['layers'] = true;
            $needs['layers_signals'] = true;
            $checks['check_signals'] = false;
            $checks['check_layers'] = false;
        }

        // Check for sources
        if ($checks['check_sources'] && str_contains($line, '/sources/')) {
            $needs['sources'] = true;
            $checks['check_sources'] = false;
        }

        // Check for filters (requires sources)
        if ($checks['check_filters'] && str_contains($line, '/filters/')) {
            $needs['sources'] = true;
            $needs['sources_filters'] = true;
            $checks['check_filters'] = false;
            $checks['check_sources'] = false;
        }

        // Check for signals on sources (requires sources)
        if ($checks['check_signals'] && str_contains($line, 'triggerSignal') && str_contains($line, '/sources/')) {
            $needs['sources'] = true;
            $needs['sources_signals'] = true;
            $checks['check_signals'] = false;
            $checks['check_sources'] = false;
        }

        // Check for layer-sets
        if ($checks['check_layer_sets'] && str_contains($line, '/layer-sets/')) {
            $needs['layer-sets'] = true;
            $checks['check_layer_sets'] = false;
        }

        // Check for output-destinations
        if ($checks['check_outputs'] && str_contains($line, '/output-destinations/')) {
            $needs['output-destinations'] = true;
            $checks['check_outputs'] = false;
        }

        // Early exit if all checks are done
        if (!in_array(true, $checks, true)) {
            break;
        }
    }

    return $needs;
}
