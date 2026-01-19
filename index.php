<?php
configuration:
    $mimoLive_API_documents = 'http://localhost:8989/api/v1/documents';
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
// First request to get all the documents
    $requests = [
        "init" =>
        [
            'url'    => $mimoLive_API_documents,
            'method' => 'GET',
        ],
    ];

    // TO-DO: Can fail (404 or 401)
    $data = multiCurlRequest($requests);
    $n = $data['init']['status'] ?? 0;

    if ($n == 0) {
        header("Content-Type: application/json");
        echo json_encode(['error' => 'Please open mimoLive and/or activate WebControl!']);
        exit(1);
    } else if ($n == 401) {
        header("Content-Type: application/json");
        echo json_encode(['error' => 'Password encryption is currently not supported. Please goto mimoLive and get rid of your password for WebControl.']);
        exit(1);
    } else if ($n != 200) {
        header("Content-Type: application/json");
        echo json_encode(['error' => 'Unknown Error during retrieval of the current documents.']);
        exit(1);
    }

namedAPI:
// Analyze script to determine what needs to be loaded
    $_GET['q'] = $_GET['q'] ?? '';
    $needs = analyzeScriptNeeds($_GET['q']);

// Build the namedAPI structure fresh on every request
// (API is live and changes constantly - layers go live/off, variants switch, etc.)
// Only load what's needed based on script analysis
    $GLOBALS['namedAPI'] = buildNamedAPI($needs);

workflow:
    eval($_GET['q']);

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
