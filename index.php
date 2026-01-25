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

    // Phase 1: load all documents and devices in parallel
    $mh = curl_multi_init();
    $curl_handles = [];

    foreach ($hosts as $host_name => $host) {
        $protocol = array_get($configuration, 'protocol/'.$host_name, default: 'http://');
        $port = array_get($configuration, 'ports/'.$host_name, default: '8989');
        $pwd_hash = array_get($configuration, 'pwd-hash/'.$host_name, default: '');

        // Documents
        $url = $protocol . $host . ':' . $port . '/api/v1/documents';

        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 5);

        if (strlen($pwd_hash) > 0) {
            curl_setopt($ch, CURLOPT_HTTPHEADER, ['Authorization: Bearer ' . $pwd_hash]);
        }

        curl_multi_add_handle($mh, $ch);
        $curl_handles[] = ['ch' => $ch, 'host' => $host_name, 'type' => 'documents'];

        // Devices
        $url_devices = $protocol . $host . ':' . $port . '/api/v1/devices';

        $ch_devices = curl_init();
        curl_setopt($ch_devices, CURLOPT_URL, $url_devices);
        curl_setopt($ch_devices, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch_devices, CURLOPT_TIMEOUT, 5);

        if (strlen($pwd_hash) > 0) {
            curl_setopt($ch_devices, CURLOPT_HTTPHEADER, ['Authorization: Bearer ' . $pwd_hash]);
        }

        curl_multi_add_handle($mh, $ch_devices);
        $curl_handles[] = ['ch' => $ch_devices, 'host' => $host_name, 'type' => 'devices'];
    }
    
    $running = null;
    do {
        curl_multi_exec($mh, $running);
        curl_multi_select($mh);
    } while ($running > 0);
    
    // Phase 1 processing: Documents and Devices
    $phase2_queue = [];

    foreach ($curl_handles as $info) {
        $ch = $info['ch'];
        $host_name = $info['host'];
        $type = $info['type'];
        $response = curl_multi_getcontent($ch);
        $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);

        if ($http_code === 200 && $response !== false) {
            $data = json_decode($response, true);
            if ($data !== null && isset($data['data'])) {

                if ($type === 'documents') {
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

                if ($type === 'devices') {
                    foreach ($data['data'] as $device) {
                        $device_id = $device['id'];
                        $device_name = $device['attributes']['name'];

                        namedAPI_set('hosts/'.$host_name.'/devices/'.$device_name.'/id', $device_id);
                        foreach ($device['attributes'] as $attr_key => $attr_value) {
                            namedAPI_set('hosts/'.$host_name.'/devices/'.$device_name.'/'.$attr_key, $attr_value);
                        }
                    }
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

                        // Store relationships (variants array and active-variant)
                        if (isset($item['relationships'])) {
                            namedAPI_set('hosts/'.$meta['host'].'/documents/'.$meta['doc_name'].'/layers/'.$name.'/relationships', $item['relationships']);
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

        if (count($parts) < 3 || $parts[0] !== 'hosts') {
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

        // Handle devices: hosts/$host/devices/$device_name
        if ($parts[2] === 'devices' && count($parts) >= 4) {
            $device_name = $parts[3];
            $device_id = namedAPI_get('hosts/'.$host_name.'/devices/'.$device_name.'/id');

            if ($device_id === null) {
                return null;
            }

            $url = $base . '/devices/' . $device_id;
            return ['url' => $url, 'pwd' => $pwd_hash];
        }

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

        foreach ($queue[$current_frame] as $action) {
            $url_data = build_api_url($action['path']);

            if ($url_data === null) {
                continue;
            }

            $full_url = $url_data['url'];
            if (strlen($action['endpoint']) > 0) {
                $full_url .= '/' . $action['endpoint'];
            }

            debug_print("DEBUG: Executing action - URL: $full_url\n");

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
                    $full_url .= '?update=' . rawurlencode($json_payload);

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

                // openwebbrowser uses GET, all other actions use POST
                if ($action['endpoint'] !== 'openwebbrowser') {
                    curl_setopt($ch, CURLOPT_POST, true);
                }
            }

            if (count($headers) > 0) {
                curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
            }

            curl_multi_add_handle($mh, $ch);
            $curl_handles[] = ['ch' => $ch];
        }

        $running = null;
        do {
            curl_multi_exec($mh, $running);
            curl_multi_select($mh);
        } while ($running > 0);

        foreach ($curl_handles as $handle_info) {
            $ch = $handle_info['ch'];
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
        'togglelive(' => 'toggleLive(',
        'recall(' => 'recall(',
        'cyclethroughvariants(' => 'cycleThroughVariants(',
        'cyclethroughvariantsbackwards(' => 'cycleThroughVariantsBackwards(',
        'bouncethroughvariants(' => 'bounceThroughVariants(',
        'bouncethroughvariantsbackwards(' => 'bounceThroughVariantsBackwards(',
        'setlivefirstvariant(' => 'setLiveFirstVariant(',
        'setlivelastvariant(' => 'setLiveLastVariant(',
        'trigger(' => 'trigger(',
        'snapshot(' => 'snapshot(',
        'openwebbrowser(' => 'openWebBrowser(',
        'butonlyif(' => 'butOnlyIf(',
        'setsleep(' => 'setSleep(',
        'setvalue(' => 'setValue(',
        'setvolume(' => 'setVolume(',
        'mimoposition(' => 'mimoPosition(',
        'mimocrop(' => 'mimoCrop(',
        'mimocolor(' => 'mimoColor(',
        'getid(' => 'getID(',
        'wait(' => 'wait(',
        'run(' => 'run(',
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

    function toggleLive($namedAPI_path) {
        global $current_frame;
        debug_print("toggleLive() called: path=$namedAPI_path\n");
        $url = build_api_url($namedAPI_path);
        debug_print("  build_api_url returned: " . ($url === null ? 'NULL' : 'valid URL') . "\n");
        if ($url === null) {
            debug_print("  SKIPPED - build_api_url returned null\n");
            return;
        }
        queue_action($current_frame, $namedAPI_path, 'toggleLive');
    }

    function recall($namedAPI_path) {
        global $current_frame;
        if (build_api_url($namedAPI_path) === null) {
            return;
        }
        queue_action($current_frame, $namedAPI_path, 'recall');
    }

    function cycleThroughVariants($namedAPI_path) {
        global $current_frame;

        // Strip /variants/* if present
        $layer_path = explode('/variants/', $namedAPI_path)[0];

        debug_print("cycleThroughVariants() called: path=$namedAPI_path, layer_path=$layer_path\n");

        if (build_api_url($layer_path) === null) {
            debug_print("  SKIPPED - build_api_url returned null\n");
            return;
        }

        queue_action($current_frame, $layer_path, 'cycleThroughVariants');
    }

    function cycleThroughVariantsBackwards($namedAPI_path) {
        global $current_frame;

        // Strip /variants/* if present
        $layer_path = explode('/variants/', $namedAPI_path)[0];

        debug_print("cycleThroughVariantsBackwards() called: path=$namedAPI_path, layer_path=$layer_path\n");

        if (build_api_url($layer_path) === null) {
            debug_print("  SKIPPED - build_api_url returned null\n");
            return;
        }

        queue_action($current_frame, $layer_path, 'cycleThroughVariantsBackwards');
    }

    function bounceThroughVariants($namedAPI_path) {
        global $current_frame;

        // Strip /variants/* if present
        $layer_path = explode('/variants/', $namedAPI_path)[0];

        debug_print("bounceThroughVariants() called: path=$namedAPI_path, layer_path=$layer_path\n");

        // Get relationships to check current variant position
        $relationships = namedAPI_get($layer_path . '/relationships');

        if ($relationships === null || !isset($relationships['variants']['data'])) {
            debug_print("  SKIPPED - no relationships/variants data found\n");
            return;
        }

        $variants = $relationships['variants']['data'];
        $active_id = $relationships['active-variant']['data']['id'] ?? null;

        // Find current position
        $current_index = -1;
        foreach ($variants as $index => $variant) {
            if ($variant['id'] === $active_id) {
                $current_index = $index;
                break;
            }
        }

        debug_print("  Current variant index: $current_index of " . count($variants) . "\n");

        // If at end, don't cycle
        if ($current_index >= count($variants) - 1) {
            debug_print("  SKIPPED - already at last variant (bounce limit reached)\n");
            return;
        }

        // Otherwise use regular cycle
        if (build_api_url($layer_path) === null) {
            debug_print("  SKIPPED - build_api_url returned null\n");
            return;
        }

        queue_action($current_frame, $layer_path, 'cycleThroughVariants');
    }

    function bounceThroughVariantsBackwards($namedAPI_path) {
        global $current_frame;

        // Strip /variants/* if present
        $layer_path = explode('/variants/', $namedAPI_path)[0];

        debug_print("bounceThroughVariantsBackwards() called: path=$namedAPI_path, layer_path=$layer_path\n");

        // Get relationships to check current variant position
        $relationships = namedAPI_get($layer_path . '/relationships');

        if ($relationships === null || !isset($relationships['variants']['data'])) {
            debug_print("  SKIPPED - no relationships/variants data found\n");
            return;
        }

        $variants = $relationships['variants']['data'];
        $active_id = $relationships['active-variant']['data']['id'] ?? null;

        // Find current position
        $current_index = -1;
        foreach ($variants as $index => $variant) {
            if ($variant['id'] === $active_id) {
                $current_index = $index;
                break;
            }
        }

        debug_print("  Current variant index: $current_index of " . count($variants) . "\n");

        // If at beginning, don't cycle
        if ($current_index <= 0) {
            debug_print("  SKIPPED - already at first variant (bounce limit reached)\n");
            return;
        }

        // Otherwise use regular cycle backwards
        if (build_api_url($layer_path) === null) {
            debug_print("  SKIPPED - build_api_url returned null\n");
            return;
        }

        queue_action($current_frame, $layer_path, 'cycleThroughVariantsBackwards');
    }

    function setLiveFirstVariant($namedAPI_path) {
        global $current_frame;

        // Strip /variants/* if present
        $layer_path = explode('/variants/', $namedAPI_path)[0];

        debug_print("setLiveFirstVariant() called: path=$namedAPI_path, layer_path=$layer_path\n");

        if (build_api_url($layer_path) === null) {
            debug_print("  SKIPPED - build_api_url returned null\n");
            return;
        }

        queue_action($current_frame, $layer_path, 'setLiveFirstVariant');
    }

    function setLiveLastVariant($namedAPI_path) {
        global $current_frame;

        // Strip /variants/* if present
        $layer_path = explode('/variants/', $namedAPI_path)[0];

        debug_print("setLiveLastVariant() called: path=$namedAPI_path, layer_path=$layer_path\n");

        if (build_api_url($layer_path) === null) {
            debug_print("  SKIPPED - build_api_url returned null\n");
            return;
        }

        queue_action($current_frame, $layer_path, 'setLiveLastVariant');
    }

    function trigger($signal_name, $namedAPI_path) {
        global $current_frame;

        debug_print("trigger() called: signal_name='$signal_name', path=$namedAPI_path\n");

        // Normalize user's signal name: remove spaces/underscores, lowercase
        $normalized_search = strtolower(str_replace(['_', ' '], '', $signal_name));
        debug_print("  Normalized search term: '$normalized_search'\n");

        // Get all input-values for this path
        $input_values = namedAPI_get($namedAPI_path . '/input-values');

        if ($input_values === null) {
            debug_print("  WARNING: No input-values found at path $namedAPI_path\n");
            return;
        }

        // Find matching signal
        $found_signal_key = null;
        foreach ($input_values as $key => $value) {
            // Only check keys ending with _TypeSignal
            if (!str_ends_with($key, '_TypeSignal')) {
                continue;
            }

            // Extract the part between __ and _TypeSignal
            // Example: tvGroup_Control__Dis_7_TypeSignal -> Dis_7
            if (preg_match('/__(.+)_TypeSignal$/', $key, $matches)) {
                $signal_part = $matches[1];
                $normalized_key = strtolower(str_replace(['_', ' '], '', $signal_part));

                debug_print("  Checking signal: '$key' -> normalized: '$normalized_key'\n");

                if ($normalized_key === $normalized_search) {
                    $found_signal_key = $key;
                    debug_print("  MATCH FOUND: '$key'\n");
                    break;
                }
            }
        }

        if ($found_signal_key === null) {
            debug_print("  WARNING: Signal '$signal_name' not found in $namedAPI_path/input-values\n");
            return;
        }

        // Build API URL
        if (build_api_url($namedAPI_path) === null) {
            debug_print("  SKIPPED - build_api_url returned null\n");
            return;
        }

        // Queue the signal trigger action
        // The endpoint will be: path/signals/SignalID
        queue_action($current_frame, $namedAPI_path, 'signals/' . $found_signal_key);
    }

    function snapshot($namedAPI_path, $width=null, $height=null, $format=null, $filepath=null) {
        debug_print("snapshot() called: path=$namedAPI_path, width=$width, height=$height, format=$format, filepath=$filepath\n");

        // Determine endpoint based on path
        $is_source = strpos($namedAPI_path, '/sources/') !== false;
        $endpoint = $is_source ? 'preview' : 'programOut';

        // Default width/height from metadata
        if ($width === null) {
            $width = namedAPI_get($namedAPI_path . '/metadata/width');
            if ($width === null) {
                $width = 1920;
                debug_print("  Using default width: $width\n");
            }
        }
        if ($height === null) {
            $height = namedAPI_get($namedAPI_path . '/metadata/height');
            if ($height === null) {
                $height = 1080;
                debug_print("  Using default height: $height\n");
            }
        }

        // Default format
        if ($format === null) {
            $format = 'png';
        }

        // Build filename if filepath not provided
        if ($filepath === null) {
            // Get show name from path
            $parts = explode('/', $namedAPI_path);
            $show_name = 'Unknown';
            $device_name = 'PGM Out';  // Default for program output

            // Extract document name (show name)
            if (isset($parts[3])) {
                $show_name = $parts[3];
            }

            // Extract device/source name if it's a source
            if ($is_source && isset($parts[5])) {
                $device_name = $parts[5];
            }

            // Build filename: "ShowName 2026-01-24 12-34-56 DeviceName 1920x1080.png"
            $timestamp = date('Y-m-d H-i-s');
            $filename = "{$show_name} {$timestamp} {$device_name} {$width}x{$height}.{$format}";
            $filepath = "./snapshots/{$filename}";
        }

        // Ensure snapshots directory exists
        $dir = dirname($filepath);
        if (!is_dir($dir)) {
            if (!mkdir($dir, 0755, true)) {
                debug_print("  WARNING: Could not create directory $dir\n");
                return;
            }
        }

        // Build API URL with query parameters
        $url_data = build_api_url($namedAPI_path);

        if ($url_data === null) {
            debug_print("  SKIPPED - build_api_url returned null\n");
            return;
        }

        $full_url = $url_data['url'] . '/' . $endpoint;
        $full_url .= "?width={$width}&height={$height}&format={$format}";

        debug_print("  Fetching snapshot from: $full_url\n");
        debug_print("  Saving to: $filepath\n");

        // Fetch the snapshot
        $ch = curl_init($full_url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);

        if (strlen($url_data['pwd']) > 0) {
            curl_setopt($ch, CURLOPT_HTTPHEADER, ['Authorization: Bearer ' . $url_data['pwd']]);
        }

        $image_data = curl_exec($ch);
        $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);

        if ($http_code !== 200 || $image_data === false) {
            debug_print("  WARNING: Failed to fetch snapshot (HTTP $http_code)\n");
            return;
        }

        // Save to file
        if (file_put_contents($filepath, $image_data) === false) {
            debug_print("  WARNING: Failed to save snapshot to $filepath\n");
            return;
        }

        debug_print("  Snapshot saved successfully (" . strlen($image_data) . " bytes)\n");
    }

    function openWebBrowser($namedAPI_path) {
        $namedAPI_path=trim($namedAPI_path, '/');
        $namedAPI_path=trim($namedAPI_path);
        $namedAPI_path=trim($namedAPI_path, '/');
        global $current_frame;
        debug_print("openWebBrowser() called: path=$namedAPI_path\n");

        // Validate it's a Web Browser source
        $source_type = namedAPI_get($namedAPI_path . '/source-type');
        debug_print("  source-type: " . ($source_type === null ? 'NULL' : "'$source_type'") . "\n");
        if ($source_type !== 'com.boinx.mimoLive.sources.webBrowserSource') {
            debug_print("  WARNING: Path is not a Web Browser source (type: $source_type)\n");
            return;
        }

        // Build API URL
        $url_data = build_api_url($namedAPI_path);
        debug_print("  build_api_url returned: " . ($url_data === null ? 'NULL' : 'valid URL') . "\n");
        if ($url_data === null) {
            debug_print("  SKIPPED - build_api_url returned null\n");
            return;
        }

        // Queue the openwebbrowser action
        debug_print("  Queueing openwebbrowser action for frame $current_frame\n");
        queue_action($current_frame, $namedAPI_path, 'openwebbrowser');
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

    function getID($path) {
        $path = trim($path, '/');
        $path = trim($path);
        $path = trim($path, '/');

        debug_print("getID() called: path=$path\n");
        $id = namedAPI_get($path . '/id');
        if ($id === null) {
            debug_print("  WARNING: Path not found, returning none-source fallback\n");
            return '2124830483-com.mimolive.source.nonesource';
        }
        debug_print("  Found ID: $id\n");
        return $id;
    }

    function mimoPosition($prefix, $width, $height, $top, $left, $namedAPI_path) {
        // Extract document path to get resolution
        $parts = explode('/', $namedAPI_path);
        if (count($parts) < 4 || $parts[2] !== 'documents') {
            debug_print("mimoPosition() ERROR: Invalid path format - expected hosts/.../documents/...\n");
            return [];
        }

        $doc_path = implode('/', array_slice($parts, 0, 4));

        // Get document resolution
        $doc_width = namedAPI_get($doc_path . '/metadata/width');
        $doc_height = namedAPI_get($doc_path . '/metadata/height');

        if ($doc_width === null || $doc_height === null) {
            debug_print("mimoPosition() ERROR: Could not get document resolution from $doc_path/metadata\n");
            return [];
        }

        // Convert percentage strings to pixel values
        if (is_string($width) && str_ends_with($width, '%')) {
            $width = ($doc_width * (float)rtrim($width, '%')) / 100.0;
        }
        if (is_string($height) && str_ends_with($height, '%')) {
            $height = ($doc_height * (float)rtrim($height, '%')) / 100.0;
        }
        if (is_string($top) && str_ends_with($top, '%')) {
            $top = ($doc_height * (float)rtrim($top, '%')) / 100.0;
        }
        if (is_string($left) && str_ends_with($left, '%')) {
            $left = ($doc_width * (float)rtrim($left, '%')) / 100.0;
        }

        debug_print("mimoPosition(): doc_resolution={$doc_width}x{$doc_height}, requested={$width}x{$height} at top=$top, left=$left\n");

        // Calculate right and bottom distances from edges
        $right = $doc_width - $left - $width;
        $bottom = $doc_height - $top - $height;

        // Convert to mimoLive units (0-2 range, where 1 is center)
        $units_left = ($left / $doc_width) * 2;
        $units_top = ($top / $doc_height) * 2;
        $units_right = ($right / $doc_width) * 2;
        $units_bottom = ($bottom / $doc_height) * 2;

        debug_print("  units: left=$units_left, top=$units_top, right=$units_right, bottom=$units_bottom\n");

        // Build result array with proper key names
        return [
            $prefix . '_Left_TypeBoinxX' => $units_left,
            $prefix . '_Top_TypeBoinxY' => $units_top,
            $prefix . '_Right_TypeBoinxX' => $units_right,
            $prefix . '_Bottom_TypeBoinxY' => $units_bottom
        ];
    }

    function mimoCrop($prefix, $top, $bottom, $left, $right, $namedAPI_path = null) {
        // If any value is a pixel number (not a percentage string), we need resolution
        $needs_resolution = !is_string($top) || !str_ends_with($top, '%') ||
                           !is_string($bottom) || !str_ends_with($bottom, '%') ||
                           !is_string($left) || !str_ends_with($left, '%') ||
                           !is_string($right) || !str_ends_with($right, '%');

        $width = null;
        $height = null;

        if ($needs_resolution) {
            if ($namedAPI_path === null) {
                debug_print("mimoCrop() ERROR: namedAPI_path required when using pixel values\n");
                return [];
            }

            // Extract document path
            $parts = explode('/', $namedAPI_path);
            if (count($parts) < 4 || $parts[2] !== 'documents') {
                debug_print("mimoCrop() ERROR: Invalid path format - expected hosts/.../documents/...\n");
                return [];
            }

            $doc_path = implode('/', array_slice($parts, 0, 4));

            // Check if path points to a source directly
            if (strpos($namedAPI_path, '/sources/') !== false) {
                $summary = namedAPI_get($namedAPI_path . '/summary');
                if ($summary !== null) {
                    // Parse summary: "Dynamic. 1920 × 1080 px" or "Image. 886 × 886"
                    if (preg_match('/(\d+)\s*[×x]\s*(\d+)/', $summary, $matches)) {
                        $width = (int)$matches[1];
                        $height = (int)$matches[2];
                    }
                }
            }

            // Fallback: use document resolution
            if ($width === null || $height === null) {
                $width = namedAPI_get($doc_path . '/metadata/width');
                $height = namedAPI_get($doc_path . '/metadata/height');
            }

            if ($width === null || $height === null) {
                debug_print("mimoCrop() ERROR: Could not determine SOURCE resolution\n");
                return [];
            }
        }

        // Convert values to percentages
        if (is_string($top) && str_ends_with($top, '%')) {
            $top_percent = (float)rtrim($top, '%');
        } else {
            $top_percent = ($top / $height) * 100.0;
        }

        if (is_string($bottom) && str_ends_with($bottom, '%')) {
            $bottom_percent = (float)rtrim($bottom, '%');
        } else {
            $bottom_percent = ($bottom / $height) * 100.0;
        }

        if (is_string($left) && str_ends_with($left, '%')) {
            $left_percent = (float)rtrim($left, '%');
        } else {
            $left_percent = ($left / $width) * 100.0;
        }

        if (is_string($right) && str_ends_with($right, '%')) {
            $right_percent = (float)rtrim($right, '%');
        } else {
            $right_percent = ($right / $width) * 100.0;
        }

        // Build result array with proper key names
        return [
            $prefix . '_Top' => $top_percent,
            $prefix . '_Bottom' => $bottom_percent,
            $prefix . '_Left' => $left_percent,
            $prefix . '_Right' => $right_percent
        ];
    }

    function mimoColor($color_string) {
        $color_string = trim($color_string);

        // Hex format: #...
        if ($color_string[0] === '#') {
            $hex = substr($color_string, 1);
            $len = strlen($hex);

            // Expand to 8 characters (RRGGBBAA)
            if ($len === 1) {
                // #A → #AAAAAAFF
                $hex = str_repeat($hex, 6) . 'FF';
            } elseif ($len === 2) {
                // #AB → #AAAAAABB (6x first + 2x second)
                $hex = str_repeat($hex[0], 6) . str_repeat($hex[1], 2);
            } elseif ($len === 3) {
                // #F73 → #FF7733FF
                $hex = $hex[0].$hex[0].$hex[1].$hex[1].$hex[2].$hex[2].'FF';
            } elseif ($len === 4) {
                // #F73A → #FF7733AA
                $hex = $hex[0].$hex[0].$hex[1].$hex[1].$hex[2].$hex[2].$hex[3].$hex[3];
            } elseif ($len === 6) {
                // #FF5733 → #FF5733FF
                $hex = $hex . 'FF';
            } elseif ($len === 8) {
                // #FF5733AA → #FF5733AA
                // Already 8 characters
            } else {
                debug_print("mimoColor() ERROR: Invalid hex color length: $color_string\n");
                return ['red' => 0, 'green' => 0, 'blue' => 0, 'alpha' => 1];
            }

            // Convert to 0-1 range
            $r = hexdec(substr($hex, 0, 2)) / 255.0;
            $g = hexdec(substr($hex, 2, 2)) / 255.0;
            $b = hexdec(substr($hex, 4, 2)) / 255.0;
            $a = hexdec(substr($hex, 6, 2)) / 255.0;

            return ['red' => $r, 'green' => $g, 'blue' => $b, 'alpha' => $a];
        }

        // Comma-separated format: R,G,B,A
        if (strpos($color_string, ',') !== false) {
            $parts = array_map('trim', explode(',', $color_string));

            // Check if percentage format
            if (strpos($parts[0], '%') !== false) {
                // Percentage format: 100%,50%,0%,100%
                $r = (float)str_replace('%', '', $parts[0]) / 100.0;
                $g = (float)str_replace('%', '', $parts[1]) / 100.0;
                $b = (float)str_replace('%', '', $parts[2]) / 100.0;
                $a = isset($parts[3]) ? (float)str_replace('%', '', $parts[3]) / 100.0 : 1.0;
            } else {
                // 0-255 format: 255,128,64,255
                $r = (float)$parts[0] / 255.0;
                $g = (float)$parts[1] / 255.0;
                $b = (float)$parts[2] / 255.0;
                $a = isset($parts[3]) ? (float)$parts[3] / 255.0 : 1.0;
            }

            return ['red' => $r, 'green' => $g, 'blue' => $b, 'alpha' => $a];
        }

        debug_print("mimoColor() ERROR: Unknown color format: $color_string\n");
        return ['red' => 0, 'green' => 0, 'blue' => 0, 'alpha' => 1];
    }

    function setSleep($frac_seconds, $reloadNamedAPI=true) {
        global $current_frame, $framerate, $namedAPI;

        $total_frames = max(1, ceil($frac_seconds * $framerate));
        $frame_sleep_seconds = 1.0 / $framerate;

        for ($i = 0; $i < $total_frames; $i++) {
            process_current_frame();
            $current_frame++;
            if ($i < $total_frames - 1) {
                wait($frame_sleep_seconds);
            }
        }

        // Reload namedAPI after block execution if requested
        if ($reloadNamedAPI) {
            $namedAPI = [];
            build_namedAPI();
        }
    }

    function wait($seconds) {
        // Split into integer seconds and fractional part
        // usleep() has limits and doesn't work well with large values
        $int_seconds = floor($seconds);
        $frac_seconds = $seconds - $int_seconds;

        if ($int_seconds > 0) {
            sleep($int_seconds);
        }

        if ($frac_seconds > 0) {
            usleep((int)($frac_seconds * 1000000));
        }
    }

    function butOnlyIf($path, $comp, $value1=null, $value2=null, $andSleep=0) {
        global $queue, $current_frame, $namedAPI;

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
        } else {
            // Condition was true - execute queue
            process_current_frame();
            $current_frame++;

            // Optional sleep after execution
            if ($andSleep > 0) {
                wait($andSleep);
            }

            // Always reload namedAPI after successful execution
            $namedAPI = [];
            build_namedAPI();
        }
    }

    function run($sleep=0, $reloadNamedAPI=false) {
        extract_framerate();
        setSleep($sleep, $reloadNamedAPI);
        exit();
    }

execute_user_script:
    eval($script);