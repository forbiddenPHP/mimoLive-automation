<?php
/**
 * test-new-queue-structure.php
 *
 * Test the new block-based queue structure
 */

// Include queue functions
include('../functions/queue.php');

echo "=== NEW QUEUE STRUCTURE TEST ===" . PHP_EOL . PHP_EOL;

// Example 1: Simple queue with sleep blocks
echo "Example 1: Building queue from demo.php" . PHP_EOL;
echo "========================================" . PHP_EOL;

$base_path = 'hosts/master/documents/forbiddenPHP/';

// Simulate demo.php queue building
for ($i = 0; $i < 2; ++$i) {
    setSleep(2);
    setOff($base_path . 'layers/MEv');
    setVolume($base_path . 'layers/MEv', 0);
    setOff($base_path . 'layers/MEa');
    setVolume($base_path . 'layers/MEa', 0);
    setSleep(2);
    setLive($base_path . 'layers/MEv');
    setLive($base_path . 'layers/MEa');
    setVolume($base_path . 'layers/MEa', 1);
    setVolume($base_path . 'layers/MEv', 0);
}
setSleep(2);
setLive($base_path . 'layers/RunAndStop/variants/stop');

echo PHP_EOL . "Queue structure:" . PHP_EOL;
echo "---------------" . PHP_EOL;

foreach ($GLOBALS['queue'] as $blockNum => $block) {
    echo "Block $blockNum: type={$block['type']}";

    if ($block['type'] === 'sleep') {
        echo ", duration={$block['duration']}s" . PHP_EOL;
    } elseif ($block['type'] === 'parallel') {
        echo ", actions=" . count($block['actions']) . PHP_EOL;
        foreach ($block['actions'] as $idx => $action) {
            echo "  - {$action['action']} on {$action['path']}";
            if ($action['data'] !== null) {
                echo " (data: " . json_encode($action['data']) . ")";
            }
            echo PHP_EOL;
        }
    }
}

echo PHP_EOL . "Total blocks: " . count($GLOBALS['queue']) . PHP_EOL;
echo PHP_EOL;

// Clear queue
$GLOBALS['queue'] = [];
$GLOBALS['currentBlock'] = -1;

// Example 2: Demonstrate expected execution order
echo "Example 2: Expected execution flow" . PHP_EOL;
echo "===================================" . PHP_EOL;

setSleep(1);
setOff($base_path . 'layers/A');
setOff($base_path . 'layers/B');
setSleep(2);
setLive($base_path . 'layers/C');

echo "Execution order:" . PHP_EOL;
$executionStep = 1;

foreach ($GLOBALS['queue'] as $block) {
    if ($block['type'] === 'sleep') {
        echo "$executionStep. SLEEP {$block['duration']}s (PHP sleep)" . PHP_EOL;
        $executionStep++;
    } elseif ($block['type'] === 'parallel') {
        echo "$executionStep. PARALLEL BLOCK (multiCurl):" . PHP_EOL;
        foreach ($block['actions'] as $action) {
            echo "   - {$action['action']} {$action['path']}" . PHP_EOL;
        }
        echo "   (wait for all responses)" . PHP_EOL;
        $executionStep++;
    }
}

echo PHP_EOL;
echo "Key points:" . PHP_EOL;
echo "- Sleep blocks execute synchronously (PHP sleep)" . PHP_EOL;
echo "- Parallel blocks execute all actions via multiCurl at once" . PHP_EOL;
echo "- Next block starts ONLY after previous block completes" . PHP_EOL;
echo "- No timing calculations, just wait for actual responses" . PHP_EOL;
