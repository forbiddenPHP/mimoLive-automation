<?php
// Allow unlimited execution time for long-running scripts
set_time_limit(0);

configuration:
    // Read current show
    $currentShowConfig = parse_ini_file(__DIR__ . '/config/current-show.ini', true);
    $currentShow = $currentShowConfig['show']['current_show'] ?? null;

    if (!$currentShow) {
        header("Content-Type: application/json");
        echo json_encode(['error' => 'No current show configured in config/current-show.ini']);
        exit(1);
    }

    // Read hosts for current show
    $hostsFile = __DIR__ . '/config/hosts-' . $currentShow . '.ini';
    if (!file_exists($hostsFile)) {
        header("Content-Type: application/json");
        echo json_encode(['error' => "Hosts file not found: config/hosts-{$currentShow}.ini"]);
        exit(1);
    }

    $hostsConfig = parse_ini_file($hostsFile, true);
    $GLOBALS['hosts'] = $hostsConfig['hosts'] ?? [];
    $GLOBALS['protocol'] = 'http';
    $GLOBALS['port'] = 8989;

    $GLOBALS['queue'] = [[]];
    $GLOBALS['changes'] = [];
    $GLOBALS['namedAPI'] = [];

session:
// We need a fixed session_id, so we can work with mimoLive on the same session.
    session_name("UMIMOLIVE");
    session_id("01UMIMOSESSION10");
    session_start();

functions:
    include('functions/setter-getter.php');
    include('functions/multiCurlRequest.php');
    include('functions/namedAPI.php');
    include('functions/queue.php');
    include('functions/analyzeScriptNeeds.php');

init:
// First request to get documents from all hosts
    $requests = [];
    foreach ($GLOBALS['hosts'] as $hostName => $hostAddress) {
        $url = $GLOBALS['protocol'] . '://' . $hostAddress . ':' . $GLOBALS['port'] . '/api/v1/documents';
        $requests[$hostName] = [
            'url'    => $url,
            'method' => 'GET',
        ];
    }

    // TO-DO: Can fail (404 or 401)
    $data = multiCurlRequest($requests);

    // Check if at least one host is reachable
    $atLeastOneHostAvailable = false;
    foreach ($data as $hostName => $response) {
        $status = $response['status'] ?? 0;

        if ($status == 401) {
            header("Content-Type: application/json");
            echo json_encode(['error' => "Host '{$hostName}': Password encryption is currently not supported. Please goto mimoLive and get rid of your password for WebControl."]);
            exit(1);
        } else if ($status == 200) {
            $atLeastOneHostAvailable = true;
        }
    }

    if (!$atLeastOneHostAvailable) {
        header("Content-Type: application/json");
        echo json_encode(['error' => 'No hosts available. Please open mimoLive and/or activate WebControl on at least one host!']);
        exit(1);
    }

namedAPI:
// Determine script source (inline or file)
    $script = '';
    if (isset($_GET['f'])) {
        // Load script from file
        $scriptFile = __DIR__ . '/scripts/' . $_GET['f'] . '.php';
        if (!file_exists($scriptFile)) {
            header("Content-Type: application/json");
            echo json_encode(['error' => "Script file not found: scripts/{$_GET['f']}.php"]);
            exit(1);
        }
        $script = file_get_contents($scriptFile);
        // Remove PHP opening tag if present
        $script = preg_replace('/^\s*<\?php\s*/i', '', $script);
    } else {
        // Use inline script from 'q' parameter
        $script = $_GET['q'] ?? '';
    }

// Analyze script to determine what needs to be loaded
    $needs = analyzeScriptNeeds($script);

// Build the namedAPI structure fresh on every request
// (API is live and changes constantly - layers go live/off, variants switch, etc.)
// Only load what's needed based on script analysis
    $GLOBALS['namedAPI'] = buildNamedAPI($needs, $data);

workflow:
    eval($script);

execute:
// Execute the queue
    $results = executeQueue($GLOBALS['namedAPI']);

output:
// Output only JSON
    header("Content-Type: application/json");
    echo json_encode([
        'success' => true,
        'changes' => $GLOBALS['changes'],
        'count' => count($GLOBALS['changes'])
    ]);
