<?php
/**
 * queue.php
 *
 * Queue system for mimoLive API actions
 * Block-based execution: sleep -> parallel actions -> sleep -> parallel actions
 */

/**
 * Initialize queue if needed
 */
function initQueue() {
    if (!isset($GLOBALS['queue']) || !is_array($GLOBALS['queue'])) {
        $GLOBALS['queue'] = [];
        $GLOBALS['currentBlock'] = -1; // No block yet
    }
}

/**
 * Get current block number
 */
function getCurrentBlockNumber() {
    initQueue();
    return $GLOBALS['currentBlock'];
}

/**
 * Add an action to the current parallel block
 *
 * @param string $path Named path (e.g., "hosts/master/documents/forbiddenPHP/layers/Comments")
 * @param string $action Action type (setLive, setOff, setVolume, etc.)
 * @param mixed $data Optional data for the action
 */
function queueAction($path, $action, $data = null) {
    initQueue();

    // If no block exists or last block is sleep, create new parallel block
    if ($GLOBALS['currentBlock'] === -1 ||
        (isset($GLOBALS['queue'][$GLOBALS['currentBlock']]) &&
         $GLOBALS['queue'][$GLOBALS['currentBlock']]['type'] === 'sleep')) {
        $GLOBALS['currentBlock']++;
        $GLOBALS['queue'][$GLOBALS['currentBlock']] = [
            'block' => $GLOBALS['currentBlock'],
            'type' => 'parallel',
            'actions' => []
        ];
    }

    // Add action to current parallel block
    $GLOBALS['queue'][$GLOBALS['currentBlock']]['actions'][] = [
        'path' => $path,
        'action' => $action,
        'data' => $data
    ];
}

/**
 * Add a sleep block to the queue
 *
 * @param float $seconds Seconds to sleep (supports fractions, e.g., 0.5 for 500ms)
 */
function setSleep($seconds) {
    initQueue();

    // Initialize currentBlock if not set
    if (!isset($GLOBALS['currentBlock'])) {
        $GLOBALS['currentBlock'] = -1;
    }

    // Increment block counter
    $GLOBALS['currentBlock']++;

    // Add sleep block
    $GLOBALS['queue'][$GLOBALS['currentBlock']] = [
        'block' => $GLOBALS['currentBlock'],
        'type' => 'sleep',
        'duration' => (float)$seconds
    ];
}

/**
 * Execute all queued blocks sequentially
 * Each block is either a sleep or a parallel execution of actions
 *
 * @param array $namedAPI Named API reference (will be updated)
 * @return array Results of executed actions
 */
function executeQueue(&$namedAPI) {
    initQueue();

    if (empty($GLOBALS['queue'])) {
        return [];
    }

    $allResults = [];

    // Execute each block sequentially
    foreach ($GLOBALS['queue'] as $block) {
        if ($block['type'] === 'sleep') {
            // Execute sleep block
            $duration = $block['duration'];
            sleep((int)$duration);
            $fractional = $duration - floor($duration);
            if ($fractional > 0) {
                usleep((int)($fractional * 1000000));
            }
        } elseif ($block['type'] === 'parallel') {
            // Execute parallel block
            $blockResults = executeParallelBlock($block, $namedAPI);
            $allResults = array_merge($allResults, $blockResults);
        }
    }

    // Clear queue after execution
    $GLOBALS['queue'] = [];
    $GLOBALS['currentBlock'] = -1;

    // Store changes in GLOBALS
    $GLOBALS['changes'] = $allResults;

    return $allResults;
}

/**
 * Execute a parallel block (all actions in parallel via multiCurl)
 *
 * @param array $block Block structure
 * @param array $namedAPI Named API reference
 * @return array Results
 */
function executeParallelBlock($block, &$namedAPI) {
    $requests = [];
    $requestMeta = [];
    $results = [];
    $sequentialProcesses = [];

    foreach ($block['actions'] as $index => $action) {
        // Check if it's a sequential sub-block (for animateVolumeTo/animateGainTo)
        if (isset($action['type']) && $action['type'] === 'sequential') {
            // Start sequential execution in background
            $sequentialProcesses[] = [
                'index' => $index,
                'action' => $action
            ];
            continue;
        }

        // Regular action - prepare for parallel execution
        $path = $action['path'];
        $actionType = $action['action'];
        $data = $action['data'];

        // Resolve path to UUIDs and get host info
        $resolved = buildUUIDPath($path, $namedAPI);
        if ($resolved === null) {
            $results[] = [
                'path' => $path,
                'action' => $actionType,
                'result' => ['error' => 'Path not found: ' . $path]
            ];
            continue;
        }

        // Build API endpoint
        $hostName = $resolved['host'];
        $uuidPath = $resolved['path'];
        $hostAddress = $GLOBALS['hosts'][$hostName] ?? null;

        if ($hostAddress === null) {
            $results[] = [
                'path' => $path,
                'action' => $actionType,
                'result' => ['error' => 'Host not found: ' . $hostName]
            ];
            continue;
        }

        $baseUrl = $GLOBALS['protocol'] . '://' . $hostAddress . ':' . $GLOBALS['port'] . '/api/v1';
        $endpoint = $baseUrl . '/' . $uuidPath;
        $method = 'GET';
        $requestData = null;

        switch ($actionType) {
            case 'setLive':
                $endpoint .= '/setLive';
                break;

            case 'setOff':
                $endpoint .= '/setOff';
                break;

            case 'recall':
                $endpoint .= '/recall';
                break;

            case 'setLiveFirstVariant':
                $endpoint .= '/setLiveFirstVariant';
                break;

            case 'setLiveLastVariant':
                $endpoint .= '/setLiveLastVariant';
                break;

            case 'cycleThroughVariants':
                $endpoint .= '/cycleThroughVariants';
                break;

            case 'cycleThroughVariantsBackwards':
                $endpoint .= '/cycleThroughVariantsBackwards';
                break;

            case 'setVolume':
                $method = 'GET';
                $requestData = ['volume' => $data];
                $endpoint .= '?update=' . urlencode(json_encode(['volume' => $data]));
                break;

            case 'setGain':
                $method = 'GET';
                $requestData = ['gain' => $data];
                $endpoint .= '?update=' . urlencode(json_encode(['gain' => $data]));
                break;

            case 'updateAttributes':
                $method = 'GET';
                $endpoint .= '?update=' . urlencode(json_encode($data));
                break;

            case 'triggerSignal':
                $signalName = $data['signal-name'];
                $signalsPath = $path . '/signals';
                $signals = array_get($namedAPI, $signalsPath);

                if (!isset($signals[$signalName])) {
                    $results[] = [
                        'path' => $path,
                        'action' => $actionType,
                        'result' => ['error' => 'Signal not found: ' . $signalName]
                    ];
                    continue 2;
                }

                $realSignalId = $signals[$signalName]['real-signal-id'];
                $endpoint .= '/signals/' . $realSignalId;
                break;

            default:
                $results[] = [
                    'path' => $path,
                    'action' => $actionType,
                    'result' => ['error' => 'Unknown action: ' . $actionType]
                ];
                continue 2;
        }

        // Add to parallel request batch
        $requests["req_{$index}"] = [
            'url' => $endpoint,
            'method' => $method,
            'data' => $requestData
        ];

        // Track metadata
        $requestMeta["req_{$index}"] = [
            'index' => $index,
            'path' => $path,
            'action' => $actionType
        ];
    }

    // Execute parallel requests first (fast, non-blocking in mimoLive)
    if (!empty($requests)) {
        $responses = multiCurlRequest($requests);
        $results = array_merge($results, processBlockResponses($responses, $requestMeta, $namedAPI));
    }

    // Expand all sequential animations into step arrays
    $sequentialSteps = [];
    $maxSteps = 0;
    $globalSleepBetweenSteps = null;

    foreach ($sequentialProcesses as $seqIndex => $seqProc) {
        $action = $seqProc['action'];

        if (isset($action['animation_type'])) {
            // Expand animation at execution time
            $path = $action['path'];
            $targetValue = $action['target_value'];
            $steps = $action['steps'];
            $sleepBetweenSteps = $action['sleep_between_steps'];
            $animationType = $action['animation_type'];

            // Set global sleep duration (use first one, they should all be the same FPS)
            if ($globalSleepBetweenSteps === null) {
                $globalSleepBetweenSteps = $sleepBetweenSteps;
            }

            // Get current value from namedAPI
            if ($animationType === 'volume') {
                $currentValue = array_get($namedAPI, $path . '/attributes/volume');
                if ($currentValue === null) {
                    $currentValue = 0.0;
                }
                $currentValue = max(0.0, min(1.0, (float)$currentValue));
                $actionName = 'setVolume';
            } else { // gain
                $currentValue = array_get($namedAPI, $path . '/attributes/gain');
                if ($currentValue === null) {
                    $currentValue = 1.0;
                }
                $currentValue = max(0.0, min(2.0, (float)$currentValue));
                $actionName = 'setGain';
            }

            // If already at target, skip
            if ($currentValue == $targetValue || $steps <= 0) {
                $sequentialSteps[$seqIndex] = [];
                continue;
            }

            // Build animation steps
            $stepValue = ($targetValue - $currentValue) / $steps;
            $stepArray = [];
            for ($i = 1; $i <= $steps; $i++) {
                $value = $currentValue + ($stepValue * $i);
                if ($animationType === 'volume') {
                    $value = max(0.0, min(1.0, $value));
                } else {
                    $value = max(0.0, min(2.0, $value));
                }

                $stepArray[] = [
                    'path' => $path,
                    'action' => $actionName,
                    'data' => $value
                ];
            }

            $sequentialSteps[$seqIndex] = $stepArray;
            $maxSteps = max($maxSteps, count($stepArray));
        } else {
            // Regular sequential sub-block with pre-built actions
            $sequentialSteps[$seqIndex] = $action['actions'];
            $maxSteps = max($maxSteps, count($action['actions']));
            if ($globalSleepBetweenSteps === null) {
                $globalSleepBetweenSteps = $action['sleep_between_steps'] ?? null;
            }
        }
    }

    // Execute all sequential steps in lockstep (interleaved)
    for ($stepNum = 0; $stepNum < $maxSteps; $stepNum++) {
        // Execute one step from each sequential process in parallel
        foreach ($sequentialSteps as $seqIndex => $steps) {
            if (isset($steps[$stepNum])) {
                $seqAction = $steps[$stepNum];
                $seqResult = executeSingleAction($seqAction, $namedAPI, null); // No sleep in executeSingleAction
                if ($seqResult !== null) {
                    $results[] = $seqResult;
                }
            }
        }

        // Sleep once after all parallel sequential steps (except after last step)
        if ($stepNum < $maxSteps - 1 && $globalSleepBetweenSteps !== null && $globalSleepBetweenSteps > 0) {
            $duration = (float)$globalSleepBetweenSteps;
            sleep((int)$duration);
            $fractional = $duration - floor($duration);
            if ($fractional > 0) {
                usleep((int)($fractional * 1000000));
            }
        }
    }

    return $results;
}

/**
 * Execute a single action (used for sequential sub-blocks)
 *
 * @param array $action Action structure
 * @param array $namedAPI Named API reference
 * @param float|null $sleepAfter Optional sleep duration after execution
 * @return array|null Result or null on error
 */
function executeSingleAction($action, &$namedAPI, $sleepAfter = null) {
    // Build request array for single action
    $requests = [];
    $requestMeta = [];

    $path = $action['path'];
    $actionType = $action['action'];
    $data = $action['data'];

    // Resolve and build endpoint (same logic as executeParallelBlock)
    $resolved = buildUUIDPath($path, $namedAPI);
    if ($resolved === null) {
        return [
            'path' => $path,
            'action' => $actionType,
            'result' => ['error' => 'Path not found: ' . $path]
        ];
    }

    $hostName = $resolved['host'];
    $uuidPath = $resolved['path'];
    $hostAddress = $GLOBALS['hosts'][$hostName] ?? null;

    if ($hostAddress === null) {
        return [
            'path' => $path,
            'action' => $actionType,
            'result' => ['error' => 'Host not found: ' . $hostName]
        ];
    }

    $baseUrl = $GLOBALS['protocol'] . '://' . $hostAddress . ':' . $GLOBALS['port'] . '/api/v1';
    $endpoint = $baseUrl . '/' . $uuidPath;

    // Build endpoint based on action type (simplified for now)
    if ($actionType === 'setVolume') {
        $endpoint .= '?update=' . urlencode(json_encode(['volume' => $data]));
    } elseif ($actionType === 'setGain') {
        $endpoint .= '?update=' . urlencode(json_encode(['gain' => $data]));
    }

    // Execute single request
    $requests['single'] = ['url' => $endpoint, 'method' => 'GET', 'data' => null];
    $requestMeta['single'] = ['index' => 0, 'path' => $path, 'action' => $actionType];

    $responses = multiCurlRequest($requests);
    $results = processBlockResponses($responses, $requestMeta, $namedAPI);

    // Sleep after execution if specified
    if ($sleepAfter !== null && $sleepAfter > 0) {
        $duration = (float)$sleepAfter;
        sleep((int)$duration);
        $fractional = $duration - floor($duration);
        if ($fractional > 0) {
            usleep((int)($fractional * 1000000));
        }
    }

    return $results[0] ?? null;
}

/**
 * Process responses from multiCurlRequest
 *
 * @param array $responses Responses from multiCurlRequest
 * @param array $requestMeta Request metadata
 * @param array $namedAPI Named API reference
 * @return array Results
 */
function processBlockResponses($responses, $requestMeta, &$namedAPI) {
    $results = [];

    foreach ($responses as $key => $response) {
        $meta = $requestMeta[$key];
        $path = $meta['path'];
        $action = $meta['action'];

        $result = null;
        if ($response['status'] === 200 && !empty($response['body'])) {
            $result = $response['body'];
        } else {
            $result = ['error' => 'API call failed', 'status' => $response['status']];
            if (!empty($response['error'])) {
                $result['curl_error'] = $response['error'];
            }
        }

        $results[] = [
            'path' => $path,
            'action' => $action,
            'result' => $result
        ];

        // Update namedAPI with response data
        if ($result && !isset($result['error'])) {
            updateNamedAPIFromResponse($namedAPI, $path, $result);
        }
    }

    return $results;
}

/**
 * Update namedAPI with response data
 *
 * @param array $namedAPI Named API reference
 * @param string $path Named path
 * @param array $responseData API response data
 */
function updateNamedAPIFromResponse(&$namedAPI, $path, $responseData) {
    // Parse path to navigate namedAPI structure
    $parts = explode('/', trim($path, '/'));
    $current = &$namedAPI;

    // First part must be 'hosts'
    if ($parts[0] !== 'hosts') {
        return;
    }

    // Navigate to the target location (including hosts/$name)
    for ($i = 0; $i < count($parts); $i += 2) {
        $type = $parts[$i];
        $name = $parts[$i + 1] ?? null;

        if ($name === null) {
            break;
        }

        if (!isset($current[$type][$name])) {
            return; // Path doesn't exist
        }

        $current = &$current[$type][$name];
    }

    // Update attributes if present in response
    if (isset($responseData['data']['attributes'])) {
        $current['attributes'] = $responseData['data']['attributes'];
    }
}

/**
 * Helper functions for common actions
 */

function setLive($path) {
    // Check if it's a layer-set
    if (strpos($path, '/layer-sets/') !== false) {
        queueAction($path, 'recall');
    } else {
        queueAction($path, 'setLive');
    }
}

function setOff($path) {
    queueAction($path, 'setOff');
}

function setVolume($path, $volume) {
    // Validate volume (0.0 to 1.0) for layers
    $volume = max(0.0, min(1.0, (float)$volume));
    queueAction($path, 'setVolume', $volume);
}

function setGain($path, $gain) {
    // Validate gain (0.0 to 2.0) for sources
    $gain = max(0.0, min(2.0, (float)$gain));
    queueAction($path, 'setGain', $gain);
}

function setLiveFirstVariant($path) {
    queueAction($path, 'setLiveFirstVariant');
}

function setLiveLastVariant($path) {
    queueAction($path, 'setLiveLastVariant');
}

function cycleLayerVariantsForward($path, $bounced = false) {
    queueAction($path, 'cycleThroughVariants', ['bounced' => $bounced]);
}

function cycleLayerVariantsBackwards($path, $bounced = false) {
    queueAction($path, 'cycleThroughVariantsBackwards', ['bounced' => $bounced]);
}

function updateAttributes($path, $attributes) {
    queueAction($path, 'updateAttributes', $attributes);
}

function triggerSignal($signalName, $path) {
    // Normalize signal name (remove spaces, underscores, hyphens, lowercase)
    $normalizedSignal = strtolower(str_replace(['_', '-', ' '], '', $signalName));

    queueAction($path, 'triggerSignal', ['signal-name' => $normalizedSignal]);
}

/**
 * Animate audio volume from current value to target value
 *
 * @param string $path Layer path
 * @param float $target_value Target volume (0.0 to 1.0)
 * @param int|null $steps Number of steps (null = use FPS from document)
 */
function setAnimateVolumeTo($path, $target_value, $steps = null) {
    initQueue();

    // Validate target volume
    $target_value = max(0.0, min(1.0, (float)$target_value));

    // Get FPS from document (if $steps is null, use FPS)
    $fps = getFPSFromPath($path);
    if ($steps === null) {
        $steps = $fps;
    }

    // Calculate sleep duration between steps (1/FPS seconds)
    $sleepBetweenSteps = 1.0 / $fps;

    // Add sequential sub-block to current parallel block
    // Animation values will be calculated at execution time
    if ($GLOBALS['currentBlock'] === -1 ||
        (isset($GLOBALS['queue'][$GLOBALS['currentBlock']]) &&
         $GLOBALS['queue'][$GLOBALS['currentBlock']]['type'] === 'sleep')) {
        $GLOBALS['currentBlock']++;
        $GLOBALS['queue'][$GLOBALS['currentBlock']] = [
            'block' => $GLOBALS['currentBlock'],
            'type' => 'parallel',
            'actions' => []
        ];
    }

    $GLOBALS['queue'][$GLOBALS['currentBlock']]['actions'][] = [
        'type' => 'sequential',
        'animation_type' => 'volume',
        'path' => $path,
        'target_value' => $target_value,
        'steps' => $steps,
        'sleep_between_steps' => $sleepBetweenSteps
    ];
}

/**
 * Animate source gain from current value to target value
 *
 * @param string $path Source path
 * @param float $target_value Target gain (0.0 to 2.0)
 * @param int|null $steps Number of steps (null = use FPS from document)
 */
function setAnimateGainTo($path, $target_value, $steps = null) {
    initQueue();

    // Validate target gain
    $target_value = max(0.0, min(2.0, (float)$target_value));

    // Get FPS from document (if $steps is null, use FPS)
    $fps = getFPSFromPath($path);
    if ($steps === null) {
        $steps = $fps;
    }

    // Calculate sleep duration between steps (1/FPS seconds)
    $sleepBetweenSteps = 1.0 / $fps;

    // Add sequential sub-block to current parallel block
    // Animation values will be calculated at execution time
    if ($GLOBALS['currentBlock'] === -1 ||
        (isset($GLOBALS['queue'][$GLOBALS['currentBlock']]) &&
         $GLOBALS['queue'][$GLOBALS['currentBlock']]['type'] === 'sleep')) {
        $GLOBALS['currentBlock']++;
        $GLOBALS['queue'][$GLOBALS['currentBlock']] = [
            'block' => $GLOBALS['currentBlock'],
            'type' => 'parallel',
            'actions' => []
        ];
    }

    $GLOBALS['queue'][$GLOBALS['currentBlock']]['actions'][] = [
        'type' => 'sequential',
        'animation_type' => 'gain',
        'path' => $path,
        'target_value' => $target_value,
        'steps' => $steps,
        'sleep_between_steps' => $sleepBetweenSteps
    ];
}

/**
 * Get FPS from document based on path
 *
 * @param string $path Path containing hosts/$host/documents/$document
 * @return int FPS value (default: 30)
 */
function getFPSFromPath($path) {
    // Parse path to extract host and document
    // Expected format: hosts/$host/documents/$document/...
    $parts = explode('/', trim($path, '/'));

    if (count($parts) < 4 || $parts[0] !== 'hosts' || $parts[2] !== 'documents') {
        return 30; // Default FPS
    }

    $hostName = $parts[1];
    $documentName = $parts[3];

    // Build path to document metadata
    $documentPath = 'hosts/' . $hostName . '/documents/' . $documentName;
    $fps = array_get($GLOBALS['namedAPI'], $documentPath . '/attributes/metadata/framerate');

    return ($fps !== null && $fps > 0) ? (int)$fps : 30;
}
