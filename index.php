<?php

init:
    set_time_limit(0);
    ignore_user_abort(true);
    global $namedAPI, $configuration, $OUTPUT, $queue, $current_frame, $framerate;
    $namedAPI=[];
    $configuration=[];
    $OUTPUT=[];
    $queue=[];
    $current_frame=0;
    $framerate=30; // default, wird aus dokument überschrieben
    $everything_is_fine=false;
    $background_mode=true; // set to true in production

functions:
    function build_namedAPI() {
            global $configuration, $namedAPI;
    
    $hosts = array_get($configuration, 'hosts', default: []);
    
    // Phase 1: Alle Documents von allen Hosts parallel laden
    $mh = curl_multi_init();
    $curl_handles = [];
    
    foreach ($hosts as $host_name => $host) {
        $protocol = array_get($configuration, 'protocol/'.$host_name, default: 'http://');
        $port = array_get($configuration, 'ports/'.$host_name, default: '8989');
        $pwd_hash = array_get($configuration, 'pwd-hash/'.$host_name, default: '');
        
        $url = $protocol . $host . ':' . $port . '/api/v1/documents';
        
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 5);
        
        if (strlen($pwd_hash) > 0) {
            curl_setopt($ch, CURLOPT_HTTPHEADER, ['Authorization: Bearer ' . $pwd_hash]);
        }
        
        curl_multi_add_handle($mh, $ch);
        $curl_handles[] = ['ch' => $ch, 'host' => $host_name];
    }
    
    $running = null;
    do {
        curl_multi_exec($mh, $running);
        curl_multi_select($mh);
    } while ($running > 0);
    
    // Documents verarbeiten
    $phase2_queue = [];
    
    foreach ($curl_handles as $info) {
        $ch = $info['ch'];
        $host_name = $info['host'];
        $response = curl_multi_getcontent($ch);
        $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        
        if ($http_code === 200 && $response !== false) {
            $data = json_decode($response, true);
            if ($data !== null && isset($data['data'])) {
                
                foreach ($data['data'] as $doc) {
                    $doc_id = $doc['id'];
                    $doc_name = $doc['attributes']['name'];

                    namedAPI_set('hosts/'.$host_name.'/documents/'.$doc_name.'/id', $doc_id);
                    foreach ($doc['attributes'] as $attr_key => $attr_value) {
                        namedAPI_set('hosts/'.$host_name.'/documents/'.$doc_name.'/'.$attr_key, $attr_value);
                    }
                    
                    $protocol = array_get($configuration, 'protocol/'.$host_name, default: 'http://');
                    $port = array_get($configuration, 'ports/'.$host_name, default: '8989');
                    $host = array_get($configuration, 'hosts/'.$host_name);
                    $base = $protocol . $host . ':' . $port . '/api/v1/documents/' . $doc_id;
                    $pwd_hash = array_get($configuration, 'pwd-hash/'.$host_name, default: '');
                    
                    $phase2_queue[] = ['url' => $base.'/sources', 'host' => $host_name, 'doc_name' => $doc_name, 'doc_id' => $doc_id, 'type' => 'sources', 'pwd' => $pwd_hash];
                    $phase2_queue[] = ['url' => $base.'/layers', 'host' => $host_name, 'doc_name' => $doc_name, 'doc_id' => $doc_id, 'type' => 'layers', 'pwd' => $pwd_hash];
                    $phase2_queue[] = ['url' => $base.'/layer-sets', 'host' => $host_name, 'doc_name' => $doc_name, 'doc_id' => $doc_id, 'type' => 'layersets', 'pwd' => $pwd_hash];
                    $phase2_queue[] = ['url' => $base.'/output-destinations', 'host' => $host_name, 'doc_name' => $doc_name, 'doc_id' => $doc_id, 'type' => 'outputs', 'pwd' => $pwd_hash];
                }
            }
        }
        
        curl_multi_remove_handle($mh, $ch);
        // curl_close($ch); // deprecated since 8.5! And no need
    }
    curl_multi_close($mh);
    
    // Phase 2: Sources, Layers, Layersets, Output-Destinations parallel laden
    $mh = curl_multi_init();
    $curl_handles = [];
    
    foreach ($phase2_queue as $item) {
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $item['url']);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 5);
        
        if (strlen($item['pwd']) > 0) {
            curl_setopt($ch, CURLOPT_HTTPHEADER, ['Authorization: Bearer ' . $item['pwd']]);
        }
        
        curl_multi_add_handle($mh, $ch);
        $curl_handles[] = ['ch' => $ch, 'meta' => $item];
    }
    
    $running = null;
    do {
        curl_multi_exec($mh, $running);
        curl_multi_select($mh);
    } while ($running > 0);
    
    // Phase 2 verarbeiten
    $phase3_queue = [];
    
    foreach ($curl_handles as $info) {
        $ch = $info['ch'];
        $meta = $info['meta'];
        $response = curl_multi_getcontent($ch);
        $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        
        if ($http_code === 200 && $response !== false) {
            $data = json_decode($response, true);
            if ($data !== null && isset($data['data'])) {
                
                $protocol = array_get($configuration, 'protocol/'.$meta['host'], default: 'http://');
                $port = array_get($configuration, 'ports/'.$meta['host'], default: '8989');
                $host = array_get($configuration, 'hosts/'.$meta['host']);
                $base = $protocol . $host . ':' . $port . '/api/v1/documents/' . $meta['doc_id'];
                
                if ($meta['type'] === 'sources') {
                    foreach ($data['data'] as $item) {
                        $name = $item['attributes']['name'];
                        $id = $item['id'];
                        namedAPI_set('hosts/'.$meta['host'].'/documents/'.$meta['doc_name'].'/sources/'.$name.'/id', $id);
                        foreach ($item['attributes'] as $attr_key => $attr_value) {
                            namedAPI_set('hosts/'.$meta['host'].'/documents/'.$meta['doc_name'].'/sources/'.$name.'/'.$attr_key, $attr_value);
                        }
                        // Zusätzlich: Asset-Referenz
                        namedAPI_set('hosts/'.$meta['host'].'/assets/'.$name, $id);

                        $phase3_queue[] = ['url' => $base.'/sources/'.$id.'/filters', 'host' => $meta['host'], 'doc_name' => $meta['doc_name'], 'source_name' => $name, 'type' => 'filters', 'pwd' => $meta['pwd']];
                    }
                }

                if ($meta['type'] === 'layers') {
                    foreach ($data['data'] as $item) {
                        $name = $item['attributes']['name'];
                        $id = $item['id'];
                        namedAPI_set('hosts/'.$meta['host'].'/documents/'.$meta['doc_name'].'/layers/'.$name.'/id', $id);
                        foreach ($item['attributes'] as $attr_key => $attr_value) {
                            namedAPI_set('hosts/'.$meta['host'].'/documents/'.$meta['doc_name'].'/layers/'.$name.'/'.$attr_key, $attr_value);
                        }
                        $phase3_queue[] = ['url' => $base.'/layers/'.$id.'/variants', 'host' => $meta['host'], 'doc_name' => $meta['doc_name'], 'layer_name' => $name, 'type' => 'variants', 'pwd' => $meta['pwd']];
                    }
                }

                if ($meta['type'] === 'layersets') {
                    foreach ($data['data'] as $item) {
                        $name = $item['attributes']['name'];
                        namedAPI_set('hosts/'.$meta['host'].'/documents/'.$meta['doc_name'].'/layersets/'.$name.'/id', $item['id']);
                        foreach ($item['attributes'] as $attr_key => $attr_value) {
                            namedAPI_set('hosts/'.$meta['host'].'/documents/'.$meta['doc_name'].'/layersets/'.$name.'/'.$attr_key, $attr_value);
                        }
                    }
                }

                if ($meta['type'] === 'outputs') {
                    foreach ($data['data'] as $item) {
                        // name or title, especially if name does not exisi!
                        $name = $item['attributes']['name'] ?? $item['attributes']['title'];
                        namedAPI_set('hosts/'.$meta['host'].'/documents/'.$meta['doc_name'].'/outputs/'.$name.'/id', $item['id']);
                        foreach ($item['attributes'] as $attr_key => $attr_value) {
                            namedAPI_set('hosts/'.$meta['host'].'/documents/'.$meta['doc_name'].'/outputs/'.$name.'/'.$attr_key, $attr_value);
                        }
                    }
                }
            }
        }
        
        curl_multi_remove_handle($mh, $ch);
        // curl_close($ch); // deprecated since 8.5! And no need
    }
    curl_multi_close($mh);
    
    // Phase 3: Filters und Variants parallel laden
    $mh = curl_multi_init();
    $curl_handles = [];
    
    foreach ($phase3_queue as $item) {
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $item['url']);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 5);
        
        if (strlen($item['pwd']) > 0) {
            curl_setopt($ch, CURLOPT_HTTPHEADER, ['Authorization: Bearer ' . $item['pwd']]);
        }
        
        curl_multi_add_handle($mh, $ch);
        $curl_handles[] = ['ch' => $ch, 'meta' => $item];
    }
    
    $running = null;
    do {
        curl_multi_exec($mh, $running);
        curl_multi_select($mh);
    } while ($running > 0);
    
    // Phase 3 verarbeiten
    foreach ($curl_handles as $info) {
        $ch = $info['ch'];
        $meta = $info['meta'];
        $response = curl_multi_getcontent($ch);
        $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        
        if ($http_code === 200 && $response !== false) {
            $data = json_decode($response, true);
            if ($data !== null && isset($data['data'])) {
                
                if ($meta['type'] === 'filters') {
                    foreach ($data['data'] as $item) {
                        $name = $item['attributes']['name'];
                        namedAPI_set('hosts/'.$meta['host'].'/documents/'.$meta['doc_name'].'/sources/'.$meta['source_name'].'/filters/'.$name.'/id', $item['id']);
                        foreach ($item['attributes'] as $attr_key => $attr_value) {
                            namedAPI_set('hosts/'.$meta['host'].'/documents/'.$meta['doc_name'].'/sources/'.$meta['source_name'].'/filters/'.$name.'/'.$attr_key, $attr_value);
                        }
                    }
                }

                if ($meta['type'] === 'variants') {
                    foreach ($data['data'] as $item) {
                        $name = $item['attributes']['name'];
                        namedAPI_set('hosts/'.$meta['host'].'/documents/'.$meta['doc_name'].'/layers/'.$meta['layer_name'].'/variants/'.$name.'/id', $item['id']);
                        foreach ($item['attributes'] as $attr_key => $attr_value) {
                            namedAPI_set('hosts/'.$meta['host'].'/documents/'.$meta['doc_name'].'/layers/'.$meta['layer_name'].'/variants/'.$name.'/'.$attr_key, $attr_value);
                        }
                    }
                }
            }
        }
        
        curl_multi_remove_handle($mh, $ch);
        // curl_close($ch); // deprecated since 8.5! And no need
    }
    curl_multi_close($mh);

    // Spezielles "none" Asset für alle Hosts hinzufügen
    foreach ($hosts as $host_name => $host) {
        namedAPI_set('hosts/'.$host_name.'/assets/none', '2124830483-com.mimolive.source.nonesource');
    }

    array_set($OUTPUT, 'namedAPI', $namedAPI);
    }

    function array_get($array, $keypath, $delim='/', $default=null) {
        $keys = explode($delim, $keypath);
        $current = $array;

        foreach ($keys as $key) {
            if (!is_array($current) || !array_key_exists($key, $current)) {
                return $default;
            }
            $current = $current[$key];
        }

        return $current;
    }

    function array_set(&$array, $keypath, $value, $delim='/') {
        $keys = explode($delim, $keypath);
        $current = &$array;

        foreach ($keys as $key) {
            if (!isset($current[$key]) || !is_array($current[$key])) {
                $current[$key] = [];
            }
            $current = &$current[$key];
        }

        $current = $value;
    }
    function namedAPI_get($keypath, $delim='/', $default=null) { global $namedAPI; return array_get($namedAPI, $keypath, $delim, $default); }
    function namedAPI_set($keypath, $value, $delim='/') { global $namedAPI; array_set($namedAPI, $keypath, $value, $delim); }

    function extract_framerate() {
        global $framerate, $configuration;
        $hosts = array_get($configuration, 'hosts', default: []);
        foreach ($hosts as $host_name => $host) {
            $docs = namedAPI_get('hosts/'.$host_name.'/documents', default: []);
            if (is_array($docs)) {
                foreach ($docs as $doc_name => $doc_data) {
                    $fr = namedAPI_get('hosts/'.$host_name.'/documents/'.$doc_name.'/framerate', default: null);
                    if ($fr !== null) {
                        $framerate = floatval($fr);
                        return;
                    }
                }
            }
        }
    }

    function build_api_url($namedAPI_path) {
        global $configuration;

        $parts = explode('/', trim($namedAPI_path, '/'));

        if (count($parts) < 4 || $parts[0] !== 'hosts') {
            return null;
        }

        $host_name = $parts[1];
        $host = array_get($configuration, 'hosts/'.$host_name);
        $protocol = array_get($configuration, 'protocol/'.$host_name, default: 'http://');
        $port = array_get($configuration, 'ports/'.$host_name, default: '8989');
        $pwd_hash = array_get($configuration, 'pwd-hash/'.$host_name, default: '');

        if ($host === null) {
            return null;
        }

        $base = $protocol . $host . ':' . $port . '/api/v1';

        if ($parts[2] === 'documents' && count($parts) >= 4) {
            $doc_name = $parts[3];
            $doc_id = namedAPI_get('hosts/'.$host_name.'/documents/'.$doc_name.'/id');

            if ($doc_id === null) {
                return null;
            }

            $url = $base . '/documents/' . $doc_id;

            if (count($parts) < 6) {
                return null;
            }

            if ($parts[4] === 'layers') {
                $layer_name = $parts[5];
                $layer_id = namedAPI_get('hosts/'.$host_name.'/documents/'.$doc_name.'/layers/'.$layer_name.'/id');

                if ($layer_id === null) {
                    return null;
                }

                $url .= '/layers/' . $layer_id;

                if (count($parts) >= 8 && $parts[6] === 'variants') {
                    $variant_name = $parts[7];
                    $variant_id = namedAPI_get('hosts/'.$host_name.'/documents/'.$doc_name.'/layers/'.$layer_name.'/variants/'.$variant_name.'/id');

                    if ($variant_id === null) {
                        return null;
                    }

                    $url .= '/variants/' . $variant_id;
                }

                return ['url' => $url, 'pwd' => $pwd_hash];
            }

            if ($parts[4] === 'layer-sets') {
                $layerset_name = $parts[5];
                $layerset_id = namedAPI_get('hosts/'.$host_name.'/documents/'.$doc_name.'/layersets/'.$layerset_name.'/id');

                if ($layerset_id === null) {
                    return null;
                }

                $url .= '/layer-sets/' . $layerset_id;
                return ['url' => $url, 'pwd' => $pwd_hash];
            }

            if ($parts[4] === 'outputs') {
                $output_name = $parts[5];
                $output_id = namedAPI_get('hosts/'.$host_name.'/documents/'.$doc_name.'/outputs/'.$output_name.'/id');

                if ($output_id === null) {
                    return null;
                }

                $url .= '/output-destinations/' . $output_id;
                return ['url' => $url, 'pwd' => $pwd_hash];
            }
        }

        return null;
    }

    function queue_action($frame, $namedAPI_path, $endpoint) {
        global $queue;

        if (!isset($queue[$frame])) {
            $queue[$frame] = [];
        }

        $queue[$frame][] = ['path' => $namedAPI_path, 'endpoint' => $endpoint];
    }

    function process_current_frame() {
        global $queue, $current_frame;

        if (!isset($queue[$current_frame]) || count($queue[$current_frame]) === 0) {
            return;
        }

        $mh = curl_multi_init();
        $curl_handles = [];

        foreach ($queue[$current_frame] as $action) {
            $url_data = build_api_url($action['path']);

            if ($url_data === null) {
                continue;
            }

            $full_url = $url_data['url'] . '/' . $action['endpoint'];

            $ch = curl_init();
            curl_setopt($ch, CURLOPT_URL, $full_url);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_TIMEOUT, 5);

            if (strlen($url_data['pwd']) > 0) {
                curl_setopt($ch, CURLOPT_HTTPHEADER, ['Authorization: Bearer ' . $url_data['pwd']]);
            }

            curl_multi_add_handle($mh, $ch);
            $curl_handles[] = $ch;
        }

        $running = null;
        do {
            curl_multi_exec($mh, $running);
            curl_multi_select($mh);
        } while ($running > 0);

        foreach ($curl_handles as $ch) {
            curl_multi_remove_handle($mh, $ch);
        }

        curl_multi_close($mh);

        unset($queue[$current_frame]);
    }

defaults:
    global $configuration;
    array_set($configuration, 'hosts/master', 'localhost');
    array_set($configuration, 'ports/master', '8989');
    array_set($configuration, 'protocol/master', 'http://');
    array_set($configuration, 'pwd-hash/master', ''); // '' = none
    array_set($configuration, 'show/name', 'forbiddenPHP');

config_ini:
    global $configuration;
    $config_file = __DIR__ . '/config.ini';
    if (file_exists($config_file)) {
        $ini = parse_ini_file($config_file, true);
        if ($ini !== false) {
            foreach ($ini as $section => $values) {
                foreach ($values as $key => $value) {
                    array_set($configuration, $section . '/' . $key, $value);
                }
            }
        }
    }

initial_build_namedAPI:
    build_namedAPI();

read_script:
    $script = array_get($_GET, 'q') ?? trim(' '.str_replace('<?php','',@file_get_contents('scripts/'.array_get($_GET, 'f').'.php'))) ?? '';
    if (strlen(trim($script))==0) {goto error_no_script; }

    $script = $script.'; run();';

skip_errors:
    array_set($OUTPUT, 'code', 200);
    array_set($OUTPUT, 'message', 'WOOHOO! I received a script!');
    array_set($OUTPUT, 'script', $script);
    $everything_is_fine=true;
    goto input_done;

input_errors:
    error_no_script:
    global $OUTPUT;
    $script_name=array_get($_GET, 'f', default: '');
    switch(strlen($script_name)) {
        case 0:
        array_set($OUTPUT, 'code', 400);
        array_set($OUTPUT, 'message', 'No script provided.');
        break;
        default:
        array_set($OUTPUT, 'code', 404);
        array_set($OUTPUT, 'message', 'Script scripts/'.$script_name.'.php not found');
        break;
    }
    goto input_done;


    

input_done:
    global $OUTPUT;
    http_response_code(array_get($OUTPUT, 'code', default: 500));
    header('Content-Type: application/json; charset=utf-8');
    $json_output = json_encode($OUTPUT, JSON_PRETTY_PRINT);
    header('Content-Length: ' . strlen($json_output));
    header('Connection: close');
    echo $json_output;

go_into_background:
    if ($background_mode!==true) {goto skipped;}
    ob_end_flush();
    flush();
    fastcgi_finish_request();

skipped:
    if ($everything_is_fine!==true) {exit(1);}

script_functions:
    function setLive($namedAPI_path) {
        global $current_frame;
        if (build_api_url($namedAPI_path) === null) {
            return;
        }
        queue_action($current_frame, $namedAPI_path, 'setLive');
    }

    function setOff($namedAPI_path) {
        global $current_frame;
        if (build_api_url($namedAPI_path) === null) {
            return;
        }
        queue_action($current_frame, $namedAPI_path, 'setOff');
    }

    function recall($namedAPI_path) {
        global $current_frame;
        if (build_api_url($namedAPI_path) === null) {
            return;
        }
        queue_action($current_frame, $namedAPI_path, 'recall');
    }

    function setSleep($frac_seconds) {
        global $current_frame, $framerate;

        $total_frames = max(1, ceil($frac_seconds * $framerate));
        $frame_sleep = 1000000 / $framerate;

        for ($i = 0; $i < $total_frames; $i++) {
            process_current_frame();
            $current_frame++;
            if ($i < $total_frames - 1) {
                usleep($frame_sleep);
            }
        }
    }

    function wait($seconds) {
        usleep($seconds * 1000000);
    }

    function run($sleep=0) {
        extract_framerate();
        setSleep($sleep);
    }

execute_user_script:
    eval($script);