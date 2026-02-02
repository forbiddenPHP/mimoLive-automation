<?php
datastore_test:
    // curl http://localhost:8888/?f=datastore-test&test=true - or in firefox!
    $base = 'hosts/master/documents/comments-test/'; // set your current document!
    $store = $base . 'datastores/test-store';

    // 1. Delete store if exists (clean start)
    deleteDatastore($store);
    debug_print('datastores', "1. Deleted store (clean start)\n");

setSleep(0, false);

    // 2. Create initial data
    setDatastore($store, [
        'game' => 'Quiz Show',
        'round' => 1,
        'scores' => [
            'Anna' => 0,
            'Max' => 0
        ]
    ]);
    debug_print('datastores', "2. Created initial store\n");

setSleep(0, false);

    // 3. Read entire store
    $data = getDatastore($store);
    debug_print('datastores', "3. Full store: " . json_encode($data) . "\n");

setSleep(0, false);

    // 4. Read specific value
    $round = getDatastore($store, 'round');
    debug_print('datastores', "4. Current round: $round\n");

setSleep(0, false);

    // 5. Read nested value
    $anna_score = getDatastore($store, 'scores/Anna');
    debug_print('datastores', "5. Anna's score: $anna_score\n");

setSleep(0, false);

    // 6. Update single value (merge)
    setDatastore($store, ['round' => 2]);
    debug_print('datastores', "6. Updated round to 2\n");

setSleep(0, false);

    // 7. Add new player (merge into nested)
    setDatastore($store, [
        'scores' => ['Lisa' => 0]
    ]);
    debug_print('datastores', "7. Added Lisa to scores\n");

setSleep(0, false);

    // 8. Update scores
    setDatastore($store, [
        'scores' => [
            'Anna' => 10,
            'Max' => 5,
            'Lisa' => 15
        ]
    ]);
    debug_print('datastores', "8. Updated all scores\n");

setSleep(0, false);

    // 9. Verify merge worked
    $scores = getDatastore($store, 'scores');
    debug_print('datastores', "9. All scores: " . json_encode($scores) . "\n");

setSleep(0, false);

    // 10. Full store at end
    $final = getDatastore($store);
    debug_print('datastores', "10. Final store: " . json_encode($final) . "\n");

setSleep(0, false);

    // 11. Test replace mode
    setDatastore($store, ['replaced' => true], replace: true);
    $replaced = getDatastore($store);
    debug_print('datastores', "11. After replace: " . json_encode($replaced) . "\n");

setSleep(0, false);

    // 12. Clean up
    deleteDatastore($store);
    debug_print('datastores', "12. Deleted store (cleanup)\n");


