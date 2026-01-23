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
    $framerate=30; // default, will be overwritten by the particular document
    $everything_is_fine=false;
    $background_mode=true; // set to true in production
    if (isset($_GET['test']) && $_GET['test']==true) {$background_mode=false;}


functions:
    function debug_print($message) {
        global $background_mode;
        if ($background_mode === false) {
            echo str_replace("\n", "<br>\n", $message);
        }
    }

    function build_namedAPI() {
            global $configuration, $namedAPI;
    
    $hosts = array_get($configuration, 'hosts', default: []);
    
    // Phase 1: load all documents in parallel
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
    
    // Documents: prepare
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
                    $phase2_queue[] = ['url' => $base.'/layer-sets', 'host' => $host_name, 'doc_name' => $doc_name, 'doc_id' => $doc_id, 'type' => 'layer-sets', 'pwd' => $pwd_hash];
                    $phase2_queue[] = ['url' => $base.'/output-destinations', 'host' => $host_name, 'doc_name' => $doc_name, 'doc_id' => $doc_id, 'type' => 'output-destinations', 'pwd' => $pwd_hash];
                }
            }
        }
        
        curl_multi_remove_handle($mh, $ch);
        // curl_close($ch); // deprecated since 8.5! And no need
    }
    curl_multi_close($mh);
    
    // Phase 2: Sources, Layers, Layersets, Output-Destinations load all parallel
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
    
    // Phase 2 preperation
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

                if ($meta['type'] === 'layer-sets') {
                    foreach ($data['data'] as $item) {
                        $name = $item['attributes']['name'];
                        namedAPI_set('hosts/'.$meta['host'].'/documents/'.$meta['doc_name'].'/layer-sets/'.$name.'/id', $item['id']);
                        foreach ($item['attributes'] as $attr_key => $attr_value) {
                            namedAPI_set('hosts/'.$meta['host'].'/documents/'.$meta['doc_name'].'/layer-sets/'.$name.'/'.$attr_key, $attr_value);
                        }
                    }
                }

                if ($meta['type'] === 'output-destinations') {
                    foreach ($data['data'] as $item) {
                        // name or title, especially if name does not exist!
                        $name = $item['attributes']['name'] ?? $item['attributes']['title'];
                        namedAPI_set('hosts/'.$meta['host'].'/documents/'.$meta['doc_name'].'/output-destinations/'.$name.'/id', $item['id']);
                        foreach ($item['attributes'] as $attr_key => $attr_value) {
                            namedAPI_set('hosts/'.$meta['host'].'/documents/'.$meta['doc_name'].'/output-destinations/'.$name.'/'.$attr_key, $attr_value);
                        }
                    }
                }
            }
        }
        
        curl_multi_remove_handle($mh, $ch);
        // curl_close($ch); // deprecated since 8.5! And no need
    }
    curl_multi_close($mh);
    
    // Phase 3: Filters und Variants load in parallel
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
    
    // Phase 3: process
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

    // special "none" asset for all hosts
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

    function array_flat($array, $prefix='', $delim='/') {
        $result = [];
        foreach ($array as $key => $value) {
            $path = $prefix === '' ? $key : $prefix . $delim . $key;
            if (is_array($value)) {
                $result = array_merge($result, array_flat($value, $path, $delim));
            } else {
                $result[$path] = $value;
            }
        }
        return $result;
    }

    function extract_framerate() {
        global $framerate, $configuration;
        $hosts = array_get($configuration, 'hosts', default: []);
        foreach ($hosts as $host_name => $host) {
            $docs = namedAPI_get('hosts/'.$host_name.'/documents', default: []);
            if (is_array($docs)) {
                foreach ($docs as $doc_name => $doc_data) {
                    $fr = namedAPI_get('hosts/'.$host_name.'/documents/'.$doc_name.'/metadata/framerate', default: null);
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

            // Document-level properties (e.g., programOutputMasterVolume)
            if (count($parts) == 4) {
                return ['url' => $url, 'pwd' => $pwd_hash];
            }

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

            if ($parts[4] === 'sources') {
                $source_name = $parts[5];
                $source_id = namedAPI_get('hosts/'.$host_name.'/documents/'.$doc_name.'/sources/'.$source_name.'/id');

                if ($source_id === null) {
                    return null;
                }

                $url .= '/sources/' . $source_id;

                if (count($parts) >= 8 && $parts[6] === 'filters') {
                    $filter_name = $parts[7];
                    $filter_id = namedAPI_get('hosts/'.$host_name.'/documents/'.$doc_name.'/sources/'.$source_name.'/filters/'.$filter_name.'/id');

                    if ($filter_id === null) {
                        return null;
                    }

                    $url .= '/filters/' . $filter_id;
                }

                return ['url' => $url, 'pwd' => $pwd_hash];
            }

            if ($parts[4] === 'output-destinations') {
                $output_name = $parts[5];
                $output_id = namedAPI_get('hosts/'.$host_name.'/documents/'.$doc_name.'/output-destinations/'.$output_name.'/id');

                if ($output_id === null) {
                    return null;
                }

                $url .= '/output-destinations/' . $output_id;
                return ['url' => $url, 'pwd' => $pwd_hash];
            }
        }

        return null;
    }

    function queue_action($frame, $namedAPI_path, $endpoint, $payload=null) {
        global $queue;

        if (!isset($queue[$frame])) {
            $queue[$frame] = [];
        }

        $queue[$frame][] = ['path' => $namedAPI_path, 'endpoint' => $endpoint, 'payload' => $payload];
    }

    function process_current_frame() {
        global $queue, $current_frame, $namedAPI;

        if (!isset($queue[$current_frame]) || count($queue[$current_frame]) === 0) {
            return;
        }

        $mh = curl_multi_init();
        $curl_handles = [];
        $has_recall = false;

        foreach ($queue[$current_frame] as $action) {
            $url_data = build_api_url($action['path']);

            if ($url_data === null) {
                continue;
            }

            $full_url = $url_data['url'];
            if (strlen($action['endpoint']) > 0) {
                $full_url .= '/' . $action['endpoint'];
            }

            $headers = [];
            if (strlen($url_data['pwd']) > 0) {
                $headers[] = 'Authorization: Bearer ' . $url_data['pwd'];
            }

            if ($action['payload'] !== null) {
                // Check request type
                $path_parts = explode('/', $action['path']);
                $is_document_level = (count($path_parts) == 4 && $path_parts[2] === 'documents');
                $needs_wrapper = (strpos($action['path'], '/output-destinations/') !== false);

                debug_print("DEBUG setValue: path={$action['path']}, is_document_level=" . ($is_document_level ? 'YES' : 'NO') . ", needs_wrapper=" . ($needs_wrapper ? 'YES' : 'NO') . "\n");

                // Validate payload keys in debug mode
                global $namedAPI, $background_mode;
                if ($background_mode === false) {
                    $old = namedAPI_get($action['path']);
                    if (is_array($old)) {
                        $old_flat = array_flat($old);
                        $payload_flat = array_flat($action['payload']);

                        debug_print("DEBUG: Validating " . count($payload_flat) . " payload keys\n");

                        foreach (array_keys($payload_flat) as $key) {
                            if (!array_key_exists($key, $old_flat)) {
                                debug_print("WARNING: key '$key' not found in namedAPI\n");
                                debug_print("         This will likely not work. Check spelling and capitalization!\n");
                            }
                        }
                    }
                }

                if ($needs_wrapper) {
                    // Output destinations need JSON:API format with PUT
                    $payload = [
                        'data' => [
                            'attributes' => $action['payload']
                        ]
                    ];

                    debug_print("DEBUG: Using PUT with JSON:API wrapper\n");
                    debug_print("DEBUG: URL = $full_url\n");
                    debug_print("DEBUG: Payload = " . json_encode($payload) . "\n");

                    $ch = curl_init();
                    curl_setopt($ch, CURLOPT_URL, $full_url);
                    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
                    curl_setopt($ch, CURLOPT_TIMEOUT, 5);
                    curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'PUT');
                    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload));
                    $headers[] = 'Content-Type: application/json';
                } elseif ($is_document_level) {
                    // Document-level properties use PUT without wrapper
                    $json_payload = json_encode($action['payload']);

                    debug_print("DEBUG: Using PUT without wrapper for document-level\n");
                    debug_print("DEBUG: URL = $full_url\n");
                    debug_print("DEBUG: Payload = $json_payload\n");

                    $ch = curl_init();
                    curl_setopt($ch, CURLOPT_URL, $full_url);
                    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
                    curl_setopt($ch, CURLOPT_TIMEOUT, 5);
                    curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'PUT');
                    curl_setopt($ch, CURLOPT_POSTFIELDS, $json_payload);
                    $headers[] = 'Content-Type: application/json';
                } else {
                    // Layers, variants, sources, filters use ?update= URL parameter with GET
                    $json_payload = json_encode($action['payload']);
                    $full_url .= '?update=' . urlencode($json_payload);

                    debug_print("DEBUG: Using GET with ?update= parameter\n");
                    debug_print("DEBUG: JSON payload = $json_payload\n");
                    debug_print("DEBUG: Final URL = $full_url\n");

                    $ch = curl_init();
                    curl_setopt($ch, CURLOPT_URL, $full_url);
                    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
                    curl_setopt($ch, CURLOPT_TIMEOUT, 5);
                    // GET request - no CURLOPT_POST needed
                }
            } else {
                $ch = curl_init();
                curl_setopt($ch, CURLOPT_URL, $full_url);
                curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
                curl_setopt($ch, CURLOPT_TIMEOUT, 5);
                curl_setopt($ch, CURLOPT_POST, true);
            }

            if (count($headers) > 0) {
                curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
            }

            curl_multi_add_handle($mh, $ch);
            $curl_handles[] = ['ch' => $ch, 'path' => $action['path'], 'endpoint' => $action['endpoint'], 'payload' => $action['payload']];

            if ($action['endpoint'] === 'recall') {
                $has_recall = true;
            }
        }

        $running = null;
        do {
            curl_multi_exec($mh, $running);
            curl_multi_select($mh);
        } while ($running > 0);

        foreach ($curl_handles as $handle_info) {
            $ch = $handle_info['ch'];
            $response = curl_multi_getcontent($ch);
            $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);

            if ($http_code === 200 && $response !== false && $handle_info['endpoint'] !== 'recall') {
                $data = json_decode($response, true);

                // Update live-state for setLive/setOff
                if ($data !== null && isset($data['data']['attributes']['live-state'])) {
                    $live_state = $data['data']['attributes']['live-state'];
                    namedAPI_set($handle_info['path'] . '/live-state', $live_state);
                }

                // Update namedAPI for setValue
                if ($handle_info['payload'] !== null && $data !== null) {
                    // API returns full updated object - flatten and write back to namedAPI
                    if (isset($data['data']['attributes'])) {
                        // Output-destinations: JSON:API format with data/attributes wrapper
                        $data_flat = array_flat($data['data']['attributes']);
                    } else {
                        // Layers, variants, sources, filters: plain object
                        $data_flat = array_flat($data);
                    }

                    foreach ($data_flat as $flat_key => $value) {
                        namedAPI_set($handle_info['path'] . '/' . $flat_key, $value);
                    }
                }
            }

            curl_multi_remove_handle($mh, $ch);
        }

        curl_multi_close($mh);

        if ($has_recall) {
            $namedAPI = [];
            build_namedAPI();
        }

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

check_translate:
    if (isset($_GET['translate'])) {
        global $namedAPI, $OUTPUT;
        $url = $_GET['translate'];

        // Parse URL: /api/v1/documents/{doc_id}/TYPE/{type_id}/SUB_TYPE/{sub_id}
        $pattern = '#/api/v1/documents/([^/]+)(?:/([^/]+)/([^/]+))?(?:/([^/]+)/([^/]+))?#';
        if (preg_match($pattern, $url, $matches)) {
            $doc_id = $matches[1];
            $type1 = $matches[2] ?? null;
            $id1 = $matches[3] ?? null;
            $type2 = $matches[4] ?? null;
            $id2 = $matches[5] ?? null;

            $flat = array_flat($namedAPI);
            $result = null;

            // Find document name
            foreach ($flat as $path => $value) {
                if (strpos($path, '/documents/') !== false && strpos($path, '/id') !== false && $value == $doc_id) {
                    preg_match('#hosts/([^/]+)/documents/([^/]+)/id#', $path, $m);
                    if ($m) {
                        $host = $m[1];
                        $doc_name = $m[2];
                        $result = "hosts/$host/documents/$doc_name";

                        // Find type1 name (layers, layer-sets, output-destinations, sources)
                        if ($type1 && $id1) {
                            foreach ($flat as $p => $v) {
                                if (strpos($p, "$result/$type1/") !== false && strpos($p, '/id') !== false && $v == $id1) {
                                    preg_match("#$result/$type1/([^/]+)/id#", $p, $m2);
                                    if ($m2) {
                                        $result .= "/$type1/" . $m2[1];

                                        // Find type2 name (variants, filters)
                                        if ($type2 && $id2) {
                                            foreach ($flat as $p2 => $v2) {
                                                if (strpos($p2, "$result/$type2/") !== false && strpos($p2, '/id') !== false && $v2 == $id2) {
                                                    preg_match("#$result/$type2/([^/]+)/id#", $p2, $m3);
                                                    if ($m3) {
                                                        $result .= "/$type2/" . $m3[1];
                                                        break;
                                                    }
                                                }
                                            }
                                        }
                                        break;
                                    }
                                }
                            }
                        }
                        break;
                    }
                }
            }

            $OUTPUT = ['path' => $result, 'code' => 200];
        } else {
            $OUTPUT = ['error' => 'Invalid URL format', 'code' => 400];
        }

        $everything_is_fine = true;
        goto input_done;
    }

check_list:
    if (isset($_GET['list'])) {
        global $namedAPI, $OUTPUT;
        $flat = array_flat($namedAPI);
        $filter = $_GET['list'];

        if (strlen($filter) > 0) {
            $filtered = [];
            foreach ($flat as $path => $value) {
                if (stripos($path, $filter) !== false) {
                    $filtered[$path] = $value;
                }
            }
            $OUTPUT = $filtered;
        } else {
            $OUTPUT = $flat;
        }

        array_set($OUTPUT, 'code', 200);
        $everything_is_fine = true;
        goto input_done;
    }

read_script:
    $script = array_get($_GET, 'q') ?? trim(' '.str_replace('<?php','',@file_get_contents('scripts/'.array_get($_GET, 'f').'.php'))) ?? '';
    if (strlen(trim($script))==0) {goto error_no_script; }

    $script = $script.'; run();';

    $replacements = [
        'setlive(' => 'setLive(',
        'setoff(' => 'setOff(',
        'recall(' => 'recall(',
        'butonlyif(' => 'butOnlyIf(',
        'setsleep(' => 'setSleep(',
        'setvalue(' => 'setValue(',
        ];
    $script = str_ireplace(array_keys($replacements), array_values($replacements), $script);

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
    global $OUTPUT, $background_mode;

    if ($background_mode === false) {
        // Debug mode - plain text output
        header('Content-Type: text/plain; charset=utf-8');
        // Debug output already printed during execution
    } else {
        // Production mode - JSON output
        http_response_code(array_get($OUTPUT, 'code', default: 500));
        header('Content-Type: application/json; charset=utf-8');
        $json_output = json_encode($OUTPUT, JSON_PRETTY_PRINT);
        header('Content-Length: ' . strlen($json_output));
        header('Connection: close');
        echo $json_output;
    }

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
        debug_print("setLive() called: path=$namedAPI_path\n");
        $url = build_api_url($namedAPI_path);
        debug_print("  build_api_url returned: " . ($url === null ? 'NULL' : 'valid URL') . "\n");
        if ($url === null) {
            debug_print("  SKIPPED - build_api_url returned null\n");
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

    function setValue($namedAPI_path, $updates_array) {
        $namedAPI_path=trim($namedAPI_path, '/');
        $namedAPI_path=trim($namedAPI_path);
        $namedAPI_path=trim($namedAPI_path, '/');
        global $current_frame;
        debug_print("setValue() called: path=$namedAPI_path\n");
        $url = build_api_url($namedAPI_path);
        debug_print("  build_api_url returned: " . ($url === null ? 'NULL' : 'valid URL') . "\n");
        if ($url === null) {
            debug_print("  SKIPPED - build_api_url returned null\n");
            return;
        }
        queue_action($current_frame, $namedAPI_path, '', $updates_array);
    }

    function setVolume($namedAPI_path, $value) {
        $namedAPI_path=trim($namedAPI_path, '/');
        $namedAPI_path=trim($namedAPI_path);
        $namedAPI_path=trim($namedAPI_path, '/');

        $parts = explode('/', $namedAPI_path);

        // Determine audio property based on path type
        if (count($parts) == 4 && $parts[2] === 'documents') {
            // Document level: programOutputMasterVolume
            $property = 'programOutputMasterVolume';
        } elseif (strpos($namedAPI_path, '/sources/') !== false) {
            // Source level: gain
            $property = 'gain';
        } elseif (strpos($namedAPI_path, '/layers/') !== false) {
            // Layer or variant level: volume
            $property = 'volume';
        } else {
            debug_print("setVolume() ERROR: Unknown path type for $namedAPI_path\n");
            return;
        }

        debug_print("setVolume() called: path=$namedAPI_path, property=$property, value=$value\n");
        setValue($namedAPI_path, [$property => $value]);
    }

    function setSleep($frac_seconds) {
        global $current_frame, $framerate;

        $total_frames = max(1, ceil($frac_seconds * $framerate));
        $frame_sleep = (int)(1000000 / $framerate);

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

    function sleep($seconds) {
        usleep($seconds * 1000000);
    }

    function butOnlyIf($path, $comp, $value1=null, $value2=null) {
        global $queue, $current_frame;

        $current_value = namedAPI_get($path);
        $result = false;

        switch ($comp) {
            // we have to trust php's auto-cast here!!!!
            case '==':
                $result = ($current_value == $value1);
                break;
            case '!=':
                $result = ($current_value != $value1);
                break;
            case '<':
                $result = ($current_value < $value1);
                break;
            case '>':
                $result = ($current_value > $value1);
                break;
            case '<=':
                $result = ($current_value <= $value1);
                break;
            case '>=':
                $result = ($current_value >= $value1);
                break;
            case '<>':
                $result = ($current_value >= $value1 && $current_value <= $value2);
                break;
            case '!<>':
                $result = !($current_value >= $value1 && $current_value <= $value2);
                break;
        }

        if ($result === false) {
            $queue = [];
        }

        process_current_frame();
        $current_frame++;
    }

    function run($sleep=0) {
        extract_framerate();
        setSleep($sleep);
    }

execute_user_script:
    eval($script);