<?php
/**
 * queue.php
 *
 * Queue system for mimoLive API actions
 * Collects actions and executes them efficiently in fields
 */

/**
 * Add an action to the queue
 *
 * @param string $path Named path (e.g., "hosts/master/documents/forbiddenPHP/layers/Comments")
 * @param string $action Action type (setLive, setOff, setVolume, etc.)
 * @param mixed $data Optional data for the action
 */
function queueAction($path, $action, $data = null) {
    // Initialize queue structure if needed
    if (!isset($GLOBALS['queue']) || !is_array($GLOBALS['queue'])) {
        $GLOBALS['queue'] = [[]]; // Start with first field
    }

    // Get current field index (last field)
    $currentFieldIndex = count($GLOBALS['queue']) - 1;

    // Add action to current field
    $GLOBALS['queue'][$currentFieldIndex][] = [
        'path' => $path,
        'action' => $action,
        'data' => $data,
        'timestamp' => microtime(true)
    ];
}

/**
 * Add a sleep/delay to the queue (creates a new field)
 *
 * @param float $seconds Seconds to sleep (supports fractions, e.g., 0.5 for 500ms)
 */
function setSleep($seconds) {
    // Initialize queue structure if needed
    if (!isset($GLOBALS['queue']) || !is_array($GLOBALS['queue'])) {
        $GLOBALS['queue'] = [[]];
    }

    // Convert to float to support fractions
    $seconds = (float)$seconds;

    // If current field is not empty, start a new field
    $currentFieldIndex = count($GLOBALS['queue']) - 1;
    if (!empty($GLOBALS['queue'][$currentFieldIndex])) {
        $GLOBALS['queue'][] = [];
        $currentFieldIndex++;
    }

    // Add sleep marker to this (now empty) field
    $GLOBALS['queue'][$currentFieldIndex][] = [
        'type' => 'sleep',
        'duration' => $seconds
    ];

    // Start a new field for actions after the sleep
    $GLOBALS['queue'][] = [];
}

/**
 * Start a new field in the queue (for manual field separation)
 */
function queueNewField() {
    // Initialize queue structure if needed
    if (!isset($GLOBALS['queue']) || !is_array($GLOBALS['queue'])) {
        $GLOBALS['queue'] = [[]];
    }

    // Only create new field if current field is not empty
    $currentFieldIndex = count($GLOBALS['queue']) - 1;
    if (!empty($GLOBALS['queue'][$currentFieldIndex])) {
        $GLOBALS['queue'][] = [];
    }
}

/**
 * Execute all queued actions field by field (parallelized via multiCurlRequest)
 *
 * @param array $namedAPI Named API reference (will be updated)
 * @return array Results of executed actions
 */
function executeQueue(&$namedAPI) {
    if (empty($GLOBALS['queue'])) {
        return [];
    }

    $allResults = [];

    // Execute each field sequentially
    foreach ($GLOBALS['queue'] as $fieldIndex => $field) {
        if (empty($field)) {
            continue;
        }

        $fieldResults = executeField($field, $namedAPI);
        $allResults = array_merge($allResults, $fieldResults);
    }

    // Clear queue after execution
    $GLOBALS['queue'] = [[]];

    // Store changes in GLOBALS
    $GLOBALS['changes'] = $allResults;

    return $allResults;
}

/**
 * Execute a single field (all actions in parallel, sleep sequentially)
 *
 * @param array $field Field items
 * @param array $namedAPI Named API reference
 * @return array Results
 */
function executeField($field, &$namedAPI) {
    $requests = [];
    $requestMeta = [];
    $results = [];

    foreach ($field as $index => $queueItem) {
        // Check if it's a sleep command
        if (isset($queueItem['type']) && $queueItem['type'] === 'sleep') {
            // Execute any pending requests before sleeping
            if (!empty($requests)) {
                $responses = multiCurlRequest($requests);
                $results = array_merge($results, processFieldResponses($responses, $requestMeta, $namedAPI));
                $requests = [];
                $requestMeta = [];
            }

            // Sleep
            $duration = $queueItem['duration'];
            sleep((int)$duration);
            usleep((int)(($duration - floor($duration)) * 1000000));
            continue;
        }

        // Regular action
        $path = $queueItem['path'];
        $action = $queueItem['action'];
        $data = $queueItem['data'];

        // Resolve path to UUIDs and get host info
        $resolved = buildUUIDPath($path, $namedAPI);
        if ($resolved === null) {
            $results[] = [
                'path' => $path,
                'action' => $action,
                'result' => ['error' => 'Path not found: ' . $path]
            ];
            continue;
        }

        // Build API endpoint with host-specific URL
        $hostName = $resolved['host'];
        $uuidPath = $resolved['path'];
        $hostAddress = $GLOBALS['hosts'][$hostName] ?? null;

        if ($hostAddress === null) {
            $results[] = [
                'path' => $path,
                'action' => $action,
                'result' => ['error' => 'Host not found: ' . $hostName]
            ];
            continue;
        }

        $baseUrl = $GLOBALS['protocol'] . '://' . $hostAddress . ':' . $GLOBALS['port'] . '/api/v1';
        $endpoint = $baseUrl . '/' . $uuidPath;
        $method = 'GET';
        $requestData = null;

        switch ($action) {
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
                // Look up the real signal ID from the namedAPI
                $signalName = $data['signal-name'];

                // Navigate to the signals in namedAPI
                $signalsPath = $path . '/signals';
                $signals = array_get($namedAPI, $signalsPath);

                if (!isset($signals[$signalName])) {
                    $results[] = [
                        'path' => $path,
                        'action' => $action,
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
                    'action' => $action,
                    'result' => ['error' => 'Unknown action: ' . $action]
                ];
                continue 2;
        }

        // Add to parallel request batch
        $requests["req_{$index}"] = [
            'url' => $endpoint,
            'method' => $method,
            'data' => $requestData
        ];

        // Track metadata for processing responses
        $requestMeta["req_{$index}"] = [
            'index' => $index,
            'path' => $path,
            'action' => $action
        ];
    }

    // Execute remaining requests
    if (!empty($requests)) {
        $responses = multiCurlRequest($requests);
        $results = array_merge($results, processFieldResponses($responses, $requestMeta, $namedAPI));
    }

    return $results;
}

/**
 * Process responses from multiCurlRequest
 *
 * @param array $responses Responses from multiCurlRequest
 * @param array $requestMeta Request metadata
 * @param array $namedAPI Named API reference
 * @return array Results
 */
function processFieldResponses($responses, $requestMeta, &$namedAPI) {
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
