<?php

init:
    set_time_limit(0);
    ignore_user_abort(true);
    global $namedAPI, $configuration, $OUTPUT, $queue, $current_frame;
    $namedAPI=[];
    $configuration=[];
    $OUTPUT=[];
    $queue=[];
    $current_frame=0;
    $everything_is_fine=false;
    $background_mode=true; // set to true in production
    $debug=false;
    if (isset($_GET['test']) && $_GET['test']==true) {$background_mode=false; $debug=true;}
    if (isset($_GET['realtime']) && $_GET['realtime']==true) {$background_mode=false; $debug=false;}


functions:
    function debug_print($key, $message, $frame=null) {
        global $debug, $OUTPUT, $current_frame;
        if ($debug === true) {
            $key=trim($key);
            $key=trim($key, '/');
            $key=trim($key);
            $key=trim($key, '/');

            // If frame is provided, prepend it to the key
            if ($frame !== null) {
                $key = 'frame-' . $frame . '/' . $key;
            } elseif ($current_frame !== null) {
                $key = 'frame-' . $current_frame . '/' . $key;
            }

            array_set($OUTPUT, 'debug/'.$key.'[]', trim($message));
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

                        // AutoGrid: Build structure for s_av_* layers
                        $doc_path = 'hosts/'.$meta['host'].'/documents/'.$meta['doc_name'];
                        $live_state = $item['attributes']['live-state'] ?? 'off';

                        // s_av_pos_N_group_X
                        if (preg_match('/^s_av_pos_(\d+)_group_(.+)$/', $name, $m)) {
                            $pos = (int)$m[1];
                            $group = $m[2];

                            namedAPI_set($doc_path.'/autoGrid/'.$name, [
                                'video' => $doc_path . '/layers/av_pos_' . $pos . '_group_' . $group,
                                'audio' => $doc_path . '/layers/a_pos_' . $pos . '_group_' . $group,
                                'group' => (int)$group,
                                'position' => $pos,
                                'is_presenter' => false,
                                'live_state' => $live_state
                            ]);
                        }

                        // s_av_presenter
                        if ($name === 's_av_presenter') {
                            namedAPI_set($doc_path.'/autoGrid/'.$name, [
                                'video' => $doc_path . '/layers/av_presenter',
                                'audio' => $doc_path . '/layers/a_presenter',
                                'is_presenter' => true,
                                'live_state' => $live_state
                            ]);
                        }

                        // Load variants for all layers
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
                    $doc_path = 'hosts/'.$meta['host'].'/documents/'.$meta['doc_name'];
                    $layer_name = $meta['layer_name'];
                    $active_variant = null;

                    foreach ($data['data'] as $item) {
                        $name = $item['attributes']['name'];
                        namedAPI_set($doc_path.'/layers/'.$layer_name.'/variants/'.$name.'/id', $item['id']);
                        foreach ($item['attributes'] as $attr_key => $attr_value) {
                            namedAPI_set($doc_path.'/layers/'.$layer_name.'/variants/'.$name.'/'.$attr_key, $attr_value);
                        }

                        // Track which variant is live (for autoGrid status)
                        if (($item['attributes']['live-state'] ?? null) === 'live') {
                            $active_variant = $name;
                        }
                    }

                    // If this is an s_av_* layer, update autoGrid with status
                    $autoGrid_entry = namedAPI_get($doc_path.'/autoGrid/'.$layer_name);
                    if ($autoGrid_entry !== null) {
                        $live_state = $autoGrid_entry['live_state'] ?? 'off';
                        if ($live_state === 'off') {
                            $status = 'exclude';
                        } else {
                            $status = $active_variant ?? 'video-and-audio';
                        }
                        namedAPI_set($doc_path.'/autoGrid/'.$layer_name.'/status', $status);
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

    // ===== Phase 4: Enrich autoGrid data =====
    // Now that all data is loaded, enrich autoGrid entries with precomputed values
    foreach ($hosts as $host_name => $host) {
        $docs = namedAPI_get('hosts/'.$host_name.'/documents');
        if (!is_array($docs)) continue;

        foreach ($docs as $doc_name => $doc_data) {
            $doc_path = 'hosts/'.$host_name.'/documents/'.$doc_name;
            $autoGrid = namedAPI_get($doc_path.'/autoGrid');
            if (!is_array($autoGrid)) continue;

            // Collect metadata
            $doc_width = namedAPI_get($doc_path.'/metadata/width');
            $doc_height = namedAPI_get($doc_path.'/metadata/height');
            $has_presenter = isset($autoGrid['s_av_presenter']);
            $groups = [];

            foreach ($autoGrid as $s_layer_name => $entry) {
                if (!is_array($entry)) continue;

                $video_layer = $entry['video'] ?? null;
                $audio_layer = $entry['audio'] ?? null;

                // Check if audio layer exists
                $audio_exists = ($audio_layer !== null && namedAPI_get($audio_layer.'/id') !== null);
                namedAPI_set($doc_path.'/autoGrid/'.$s_layer_name.'/audio_exists', $audio_exists);

                // Get video source from av_* layer
                $video_source = namedAPI_get($video_layer.'/input-values/tvIn_VideoSourceAImage');
                namedAPI_set($doc_path.'/autoGrid/'.$s_layer_name.'/video_source', $video_source);

                // Get audio source and level from a_* layer (if exists)
                if ($audio_exists) {
                    $audio_source = namedAPI_get($audio_layer.'/input-values/tvGroup_Source__Audio_TypeAudio');
                    $level0 = namedAPI_get($audio_layer.'/input-values/tvGroup_Source__Audio_TypeAudioAudioLevel0') ?? 0;
                    $level1 = namedAPI_get($audio_layer.'/input-values/tvGroup_Source__Audio_TypeAudioAudioLevel1') ?? 0;
                    $audio_level = ($level0 + $level1) / 2;

                    namedAPI_set($doc_path.'/autoGrid/'.$s_layer_name.'/audio_source', $audio_source);
                    namedAPI_set($doc_path.'/autoGrid/'.$s_layer_name.'/audio_level', $audio_level);
                }

                // Collect groups
                $group = $entry['group'] ?? null;
                if ($group !== null && !in_array($group, $groups)) {
                    $groups[] = $group;
                }
            }

            // Store _meta
            sort($groups);
            namedAPI_set($doc_path.'/autoGrid/_meta', [
                'doc_width' => $doc_width,
                'doc_height' => $doc_height,
                'has_presenter' => $has_presenter,
                'groups' => $groups
            ]);
        }
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

        // Check if last key ends with [] for append operation
        $last_key = array_key_last($keys);
        $append_mode = false;
        if (str_ends_with($keys[$last_key], '[]')) {
            $keys[$last_key] = substr($keys[$last_key], 0, -2);
            $append_mode = true;
        }

        foreach ($keys as $key) {
            if (!isset($current[$key]) || !is_array($current[$key])) {
                $current[$key] = [];
            }
            $current = &$current[$key];
        }

        if ($append_mode) {
            $current[] = $value;
        } else {
            $current = $value;
        }
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

        // Handle comments: hosts/$host/comments/new
        if ($parts[2] === 'comments' && count($parts) >= 4 && $parts[3] === 'new') {
            $url = $base . '/comments/new';
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
                $layerset_id = namedAPI_get('hosts/'.$host_name.'/documents/'.$doc_name.'/layer-sets/'.$layerset_name.'/id');

                if ($layerset_id === null) {
                    return null;
                }

                $url .= '/layer-sets/' . $layerset_id;
                return ['url' => $url, 'pwd' => $pwd_hash];
            }

            if ($parts[4] === 'datastores') {
                $store_id = $parts[5];
                $url .= '/datastores/' . rawurlencode($store_id);
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

    function queue_action($frame, $namedAPI_path, $endpoint, $payload=null, $allow_merge=true) {
        global $queue;

        if (!isset($queue[$frame])) {
            $queue[$frame] = [];
        }

        // Check if an action with same path+endpoint already exists (only if merging allowed)
        $found_index = null;
        if ($allow_merge) {
            foreach ($queue[$frame] as $index => $existing_action) {
                if ($existing_action['path'] === $namedAPI_path && $existing_action['endpoint'] === $endpoint) {
                    $found_index = $index;
                    break;
                }
            }
        }

        // If found and both have payloads, merge them
        if ($found_index !== null && $queue[$frame][$found_index]['payload'] !== null && $payload !== null) {
            // Flatten both payloads
            $flat_existing = array_flat($queue[$frame][$found_index]['payload']);
            $flat_new = array_flat($payload);

            // Merge (newer values override older)
            $flat_merged = array_merge($flat_existing, $flat_new);

            // Reconstruct nested structure using array_set
            $merged_payload = [];
            foreach ($flat_merged as $path => $value) {
                array_set($merged_payload, $path, $value);
            }

            // Replace existing entry
            $queue[$frame][$found_index]['payload'] = $merged_payload;

            debug_print($namedAPI_path, "MERGE: Combined payload for $namedAPI_path/$endpoint\n");
        } else {
            // Add as new entry
            $queue[$frame][] = ['path' => $namedAPI_path, 'endpoint' => $endpoint, 'payload' => $payload];
        }
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

            debug_print('execution/actions', " Executing action - URL: $full_url\n");

            $headers = [];
            if (strlen($url_data['pwd']) > 0) {
                $headers[] = 'Authorization: Bearer ' . $url_data['pwd'];
            }

            if ($action['payload'] !== null) {
                // Check request type
                $path_parts = explode('/', $action['path']);
                $is_document_level = (count($path_parts) == 4 && $path_parts[2] === 'documents');
                $needs_wrapper = (strpos($action['path'], '/output-destinations/') !== false);
                $is_comment = (strpos($action['path'], '/comments/new') !== false);

                debug_print('execution/setValue', "DEBUG setValue: path={$action['path']}, is_document_level=" . ($is_document_level ? 'YES' : 'NO') . ", needs_wrapper=" . ($needs_wrapper ? 'YES' : 'NO') . "\n");

                // Validate payload keys in debug mode
                global $namedAPI, $background_mode;
                if ($background_mode === false) {
                    $old = namedAPI_get($action['path']);
                    if (is_array($old)) {
                        $old_flat = array_flat($old);
                        $payload_flat = array_flat($action['payload']);

                        debug_print($action['path'], " Validating " . count($payload_flat) . " payload keys\n");

                        foreach (array_keys($payload_flat) as $key) {
                            if (!array_key_exists($key, $old_flat)) {
                                debug_print($action['path'], "WARNING: key '$key' not found in namedAPI\n");
                                debug_print($action['path'], "         This will likely not work. Check spelling and capitalization!\n");
                            }
                        }
                    }
                }

                if ($is_comment) {
                    // Comments use GET with URL parameters - RFC3986 uses rawurlencode
                    $query_string = http_build_query($action['payload'], '', '&', PHP_QUERY_RFC3986);
                    $full_url .= '?' . $query_string;

                    debug_print($action['path'], " Using GET with URL parameters for comment\n");
                    debug_print($action['path'], " URL = $full_url\n");

                    $ch = curl_init();
                    curl_setopt($ch, CURLOPT_URL, $full_url);
                    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
                    curl_setopt($ch, CURLOPT_TIMEOUT, 5);
                    // GET request - no POST needed
                } elseif ($needs_wrapper) {
                    // Output destinations need JSON:API format with PUT
                    $payload = [
                        'data' => [
                            'attributes' => $action['payload']
                        ]
                    ];

                    debug_print($action['path'], " Using PUT with JSON:API wrapper\n");
                    debug_print($action['path'], " URL = $full_url\n");
                    debug_print($action['path'], " Payload = " . json_encode($payload) . "\n");

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

                    debug_print($action['path'], " Using PUT without wrapper for document-level\n");
                    debug_print($action['path'], " URL = $full_url\n");
                    debug_print($action['path'], " Payload = $json_payload\n");

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

                    debug_print($action['path'], " Using GET with ?update= parameter\n");
                    debug_print($action['path'], " JSON payload = $json_payload\n");
                    debug_print($action['path'], " Final URL = $full_url\n");

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
    array_set($configuration, 'framerate/master', 30);

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
            // Split filter by spaces - all parts must match
            $filter_parts = preg_split('/\s+/', $filter, -1, PREG_SPLIT_NO_EMPTY);
            foreach ($flat as $path => $value) {
                $all_match = true;
                foreach ($filter_parts as $part) {
                    if (stripos($path, $part) === false) {
                        $all_match = false;
                        break;
                    }
                }
                if ($all_match) {
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

    $script = $script."\n"."// Automatic script end:\n".';'."\n".'setSleep(0, false);';

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
        'increment(' => 'increment(',
        'decrement(' => 'decrement(',
        'trigger(' => 'trigger(',
        'snapshot(' => 'snapshot(',
        'openwebbrowser(' => 'openWebBrowser(',
        'butonlyif(' => 'butOnlyIf(',
        'setautogrid(' => 'setAutoGrid(',
        'setsleep(' => 'setSleep(',
        'setvalue(' => 'setValue(',
        'setvolume(' => 'setVolume(',
        'setanimatevolume(' => 'setAnimateVolume(',
        'setanimatevalue(' => 'setAnimateValue(',
        'mediacontrol(' => 'mediaControl(',
        'pushcomment(' => 'pushComment(',
        'getdatastore(' => 'getDatastore(',
        'setdatastore(' => 'setDatastore(',
        'deletedatastore(' => 'deleteDatastore(',
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
    $script_array=array_filter(explode("\n", $script));
    $trimmed_script=[];
    foreach($script_array as $key => $lines) {
        $trimmed_script[$key]=trim(str_replace(["\n", "\t", '  '], '', $lines));
    }
    array_set($OUTPUT, 'script', $trimmed_script);
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
        // Debug mode - JSON output
        header('Content-Type: application/json; charset=utf-8');
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
    // mimoLive does not wait longer than 10 seconds, so we have to move everything into the background and provide a fake juhu.
    ob_end_flush();
    flush();
    fastcgi_finish_request();

skipped:
    if ($everything_is_fine!==true) {exit(1);}

script_functions:
    function mediaControl($action, $path, $input = null) {
        global $current_frame;

        // Valid media control actions (= API endpoints)
        $valid_actions = [
            'play', 'pause', 'stop', 'reverse', 'rewind', 'fastforward',
            'skiptostart', 'skiptoend', 'skipback', 'skipahead',
            'record', 'shuffle', 'repeat'
        ];

        // Validate action
        if (!in_array($action, $valid_actions)) {
            debug_print($path, "mediaControl() ERROR: Invalid action '$action'. Valid: " . implode(', ', $valid_actions) . "\n");
            return;
        }

        // For variants: shorten path to layer level
        if (strpos($path, '/variants/') !== false) {
            $path = explode('/variants/', $path)[0];
            debug_print($path, "mediaControl() Note: Variant path shortened to layer level\n");
        }

        if (build_api_url($path) === null) {
            debug_print($path, "mediaControl() ERROR: Invalid path '$path'\n");
            return;
        }

        // Build endpoint with optional input parameter
        $endpoint = $action;
        if ($input !== null) {
            $endpoint .= '?input=' . rawurlencode($input);
        }

        debug_print($path, "mediaControl() called: action=$action, path=$path" . ($input ? ", input=$input" : "") . "\n");
        queue_action($current_frame, $path, $endpoint);
    }

    function setLive($namedAPI_path) {
        global $current_frame;
        debug_print($namedAPI_path, "setLive() called: path=$namedAPI_path\n");
        $url = build_api_url($namedAPI_path);
        debug_print($namedAPI_path, "  build_api_url returned: " . ($url === null ? 'NULL' : 'valid URL') . "\n");
        if ($url === null) {
            debug_print($namedAPI_path, "  SKIPPED - build_api_url returned null\n");
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
        debug_print($namedAPI_path, "toggleLive() called: path=$namedAPI_path\n");
        $url = build_api_url($namedAPI_path);
        debug_print($namedAPI_path, "  build_api_url returned: " . ($url === null ? 'NULL' : 'valid URL') . "\n");
        if ($url === null) {
            debug_print($namedAPI_path, "  SKIPPED - build_api_url returned null\n");
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

        debug_print($layer_path, "cycleThroughVariants() called: path=$namedAPI_path, layer_path=$layer_path\n");

        if (build_api_url($layer_path) === null) {
            debug_print($layer_path, "  SKIPPED - build_api_url returned null\n");
            return;
        }

        queue_action($current_frame, $layer_path, 'cycleThroughVariants');
    }

    function cycleThroughVariantsBackwards($namedAPI_path) {
        global $current_frame;

        // Strip /variants/* if present
        $layer_path = explode('/variants/', $namedAPI_path)[0];

        debug_print($layer_path, "cycleThroughVariantsBackwards() called: path=$namedAPI_path, layer_path=$layer_path\n");

        if (build_api_url($layer_path) === null) {
            debug_print($layer_path, "  SKIPPED - build_api_url returned null\n");
            return;
        }

        queue_action($current_frame, $layer_path, 'cycleThroughVariantsBackwards');
    }

    function bounceThroughVariants($namedAPI_path) {
        global $current_frame;

        // Strip /variants/* if present
        $layer_path = explode('/variants/', $namedAPI_path)[0];

        debug_print($layer_path, "bounceThroughVariants() called: path=$namedAPI_path, layer_path=$layer_path\n");

        // Get relationships to check current variant position
        $relationships = namedAPI_get($layer_path . '/relationships');

        if ($relationships === null || !isset($relationships['variants']['data'])) {
            debug_print($layer_path, "  SKIPPED - no relationships/variants data found\n");
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

        debug_print($layer_path, "  Current variant index: $current_index of " . count($variants) . "\n");

        // If at end, don't cycle
        if ($current_index >= count($variants) - 1) {
            debug_print($layer_path, "  SKIPPED - already at last variant (bounce limit reached)\n");
            return;
        }

        // Otherwise use regular cycle
        if (build_api_url($layer_path) === null) {
            debug_print($layer_path, "  SKIPPED - build_api_url returned null\n");
            return;
        }

        queue_action($current_frame, $layer_path, 'cycleThroughVariants');
    }

    function bounceThroughVariantsBackwards($namedAPI_path) {
        global $current_frame;

        // Strip /variants/* if present
        $layer_path = explode('/variants/', $namedAPI_path)[0];

        debug_print($layer_path, "bounceThroughVariantsBackwards() called: path=$namedAPI_path, layer_path=$layer_path\n");

        // Get relationships to check current variant position
        $relationships = namedAPI_get($layer_path . '/relationships');

        if ($relationships === null || !isset($relationships['variants']['data'])) {
            debug_print($layer_path, "  SKIPPED - no relationships/variants data found\n");
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

        debug_print($layer_path, "  Current variant index: $current_index of " . count($variants) . "\n");

        // If at beginning, don't cycle
        if ($current_index <= 0) {
            debug_print($layer_path, "  SKIPPED - already at first variant (bounce limit reached)\n");
            return;
        }

        // Otherwise use regular cycle backwards
        if (build_api_url($layer_path) === null) {
            debug_print($layer_path, "  SKIPPED - build_api_url returned null\n");
            return;
        }

        queue_action($current_frame, $layer_path, 'cycleThroughVariantsBackwards');
    }

    function setLiveFirstVariant($namedAPI_path) {
        global $current_frame;

        // Strip /variants/* if present
        $layer_path = explode('/variants/', $namedAPI_path)[0];

        debug_print($layer_path, "setLiveFirstVariant() called: path=$namedAPI_path, layer_path=$layer_path\n");

        if (build_api_url($layer_path) === null) {
            debug_print($layer_path, "  SKIPPED - build_api_url returned null\n");
            return;
        }

        queue_action($current_frame, $layer_path, 'setLiveFirstVariant');
    }

    function setLiveLastVariant($namedAPI_path) {
        global $current_frame;

        // Strip /variants/* if present
        $layer_path = explode('/variants/', $namedAPI_path)[0];

        debug_print($layer_path, "setLiveLastVariant() called: path=$namedAPI_path, layer_path=$layer_path\n");

        if (build_api_url($layer_path) === null) {
            debug_print($layer_path, "  SKIPPED - build_api_url returned null\n");
            return;
        }

        queue_action($current_frame, $layer_path, 'setLiveLastVariant');
    }

    function trigger($signal_name, $namedAPI_path) {
        global $current_frame;

        debug_print($namedAPI_path, "trigger() called: signal_name='$signal_name', path=$namedAPI_path\n");

        // Normalize user's signal name: remove spaces/underscores, lowercase
        $normalized_search = strtolower(str_replace(['_', ' '], '', $signal_name));
        debug_print($namedAPI_path, "  Normalized search term: '$normalized_search'\n");

        // Get all input-values for this path
        $input_values = namedAPI_get($namedAPI_path . '/input-values');

        if ($input_values === null) {
            debug_print($namedAPI_path, "  WARNING: No input-values found at path $namedAPI_path\n");
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

                debug_print($namedAPI_path, "  Checking signal: '$key' -> normalized: '$normalized_key'\n");

                if ($normalized_key === $normalized_search) {
                    $found_signal_key = $key;
                    debug_print($namedAPI_path, "  MATCH FOUND: '$key'\n");
                    break;
                }
            }
        }

        if ($found_signal_key === null) {
            debug_print($namedAPI_path, "  WARNING: Signal '$signal_name' not found in $namedAPI_path/input-values\n");
            return;
        }

        // Build API URL
        if (build_api_url($namedAPI_path) === null) {
            debug_print($namedAPI_path, "  SKIPPED - build_api_url returned null\n");
            return;
        }

        // Queue the signal trigger action
        // The endpoint will be: path/signals/SignalID
        queue_action($current_frame, $namedAPI_path, 'signals/' . $found_signal_key);
    }

    function snapshot($namedAPI_path, $width=null, $height=null, $format=null, $filepath=null) {
        debug_print($namedAPI_path, "snapshot() called: path=$namedAPI_path, width=$width, height=$height, format=$format, filepath=$filepath\n");

        // Determine endpoint based on path
        $is_source = strpos($namedAPI_path, '/sources/') !== false;
        $endpoint = $is_source ? 'preview' : 'programOut';

        // Default width/height from metadata
        if ($width === null) {
            $width = namedAPI_get($namedAPI_path . '/metadata/width');
            if ($width === null) {
                $width = 1920;
                debug_print($namedAPI_path, "  Using default width: $width\n");
            }
        }
        if ($height === null) {
            $height = namedAPI_get($namedAPI_path . '/metadata/height');
            if ($height === null) {
                $height = 1080;
                debug_print($namedAPI_path, "  Using default height: $height\n");
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
                debug_print($namedAPI_path, "  WARNING: Could not create directory $dir\n");
                return;
            }
        }

        // Build API URL with query parameters
        $url_data = build_api_url($namedAPI_path);

        if ($url_data === null) {
            debug_print($namedAPI_path, "  SKIPPED - build_api_url returned null\n");
            return;
        }

        $full_url = $url_data['url'] . '/' . $endpoint;
        $full_url .= "?width={$width}&height={$height}&format={$format}";

        debug_print($namedAPI_path, "  Fetching snapshot from: $full_url\n");
        debug_print($namedAPI_path, "  Saving to: $filepath\n");

        // Fetch the snapshot
        $ch = curl_init($full_url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);

        if (strlen($url_data['pwd']) > 0) {
            curl_setopt($ch, CURLOPT_HTTPHEADER, ['Authorization: Bearer ' . $url_data['pwd']]);
        }

        $image_data = curl_exec($ch);
        $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);

        if ($http_code !== 200 || $image_data === false) {
            debug_print($namedAPI_path, "  WARNING: Failed to fetch snapshot (HTTP $http_code)\n");
            return;
        }

        // Save to file
        if (file_put_contents($filepath, $image_data) === false) {
            debug_print($namedAPI_path, "  WARNING: Failed to save snapshot to $filepath\n");
            return;
        }

        debug_print($namedAPI_path, "  Snapshot saved successfully (" . strlen($image_data) . " bytes)\n");
    }

    function openWebBrowser($namedAPI_path) {
        $namedAPI_path=trim($namedAPI_path, '/');
        $namedAPI_path=trim($namedAPI_path);
        $namedAPI_path=trim($namedAPI_path, '/');
        global $current_frame;
        debug_print($namedAPI_path, "openWebBrowser() called: path=$namedAPI_path\n");

        // Validate it's a Web Browser source
        $source_type = namedAPI_get($namedAPI_path . '/source-type');
        debug_print($namedAPI_path, "  source-type: " . ($source_type === null ? 'NULL' : "'$source_type'") . "\n");
        if ($source_type !== 'com.boinx.mimoLive.sources.webBrowserSource') {
            debug_print($namedAPI_path, "  WARNING: Path is not a Web Browser source (type: $source_type)\n");
            return;
        }

        // Build API URL
        $url_data = build_api_url($namedAPI_path);
        debug_print($namedAPI_path, "  build_api_url returned: " . ($url_data === null ? 'NULL' : 'valid URL') . "\n");
        if ($url_data === null) {
            debug_print($namedAPI_path, "  SKIPPED - build_api_url returned null\n");
            return;
        }

        // Queue the openwebbrowser action
        debug_print($namedAPI_path, "  Queueing openwebbrowser action for frame $current_frame\n");
        queue_action($current_frame, $namedAPI_path, 'openwebbrowser');
    }

    function setValue($namedAPI_path, $updates_array) {
        $namedAPI_path=trim($namedAPI_path, '/');
        $namedAPI_path=trim($namedAPI_path);
        $namedAPI_path=trim($namedAPI_path, '/');
        global $current_frame;
        debug_print($namedAPI_path, "setValue() called: path=$namedAPI_path\n");
        $url = build_api_url($namedAPI_path);
        debug_print($namedAPI_path, "  build_api_url returned: " . ($url === null ? 'NULL' : 'valid URL') . "\n");
        if ($url === null) {
            debug_print($namedAPI_path, "  SKIPPED - build_api_url returned null\n");
            return;
        }
        queue_action($current_frame, $namedAPI_path, '', $updates_array);
    }

    /**
     * Push a comment to mimoLive's comment system
     *
     * @param string $path The host path: "hosts/master/comments/new" or "hosts/master"
     * @param array $comment_data Comment data with keys:
     *   - username (required): Display name of commenter
     *   - comment (required): The comment text
     *   - userimageurl (optional): URL to user's avatar image
     *   - date (optional): ISO8601 date string, defaults to now
     *   - platform (optional): facebook|twitter|youtube|twitch
     *   - favorite (optional): boolean, mark as favorite
     */
    function pushComment($path, $comment_data) {
        global $current_frame;

        // Validate required fields
        if (empty($comment_data['username']) || empty($comment_data['comment'])) {
            debug_print('comments', "pushComment() ERROR: username and comment are required\n");
            return;
        }

        // Normalize path
        $path = trim($path, '/');

        // Accept "hosts/XXX/comments/new" or "hosts/XXX" - normalize to full path
        if (preg_match('#^hosts/([^/]+)$#', $path, $matches)) {
            $path = $path . '/comments/new';
        } elseif (!preg_match('#^hosts/[^/]+/comments/new$#', $path)) {
            debug_print('comments', "pushComment() ERROR: Invalid path '$path'. Use 'hosts/HOSTNAME' or 'hosts/HOSTNAME/comments/new'\n");
            return;
        }

        // Build payload - add default date if not provided
        $payload = $comment_data;
        if (empty($payload['date'])) {
            $payload['date'] = date('c');
        }
        if (isset($payload['favorite'])) {
            $payload['favorite'] = $payload['favorite'] ? 'true' : 'false';
        }

        debug_print('comments', "pushComment() called: path=$path\n");
        queue_action($current_frame, $path, '', $payload, false);  // false = don't merge, each comment is separate
    }

    /**
     * Get data from a datastore (synchronous - not queued)
     *
     * @param string $path Full path: hosts/HOSTNAME/documents/DOCNAME/datastores/STOREID
     * @param string|null $keypath Optional keypath to get specific value (e.g., 'input-values/score')
     * @param string $separator Separator for keypath (default: '/')
     * @return mixed The data, or null if not found
     */
    function getDatastore($path, $keypath = null, $separator = '/') {
        $path = trim($path, '/');

        $url_data = build_api_url($path);
        if ($url_data === null) {
            debug_print('datastores', "getDatastore() ERROR: Invalid path '$path'\n");
            return null;
        }

        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url_data['url']);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 5);

        if (strlen($url_data['pwd']) > 0) {
            curl_setopt($ch, CURLOPT_HTTPHEADER, ['Authorization: Bearer ' . $url_data['pwd']]);
        }

        $response = curl_exec($ch);
        $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        // curl_close($ch); // deprecated since PHP 8.0

        debug_print('datastores', "getDatastore() GET $path → HTTP $http_code\n");

        if ($http_code === 404) {
            return null;
        }

        if ($http_code !== 200 || $response === false) {
            debug_print('datastores', "getDatastore() ERROR: HTTP $http_code\n");
            return null;
        }

        $data = json_decode($response, true);
        if ($data === null) {
            // Not JSON - return raw response
            $data = $response;
        }

        // If keypath specified, extract that part
        if ($keypath !== null && is_array($data)) {
            return array_get($data, $keypath, delim: $separator);
        }

        return $data;
    }

    /**
     * Set data in a datastore (synchronous - not queued)
     * Deep-merges with existing data by default
     *
     * @param string $path Full path: hosts/HOSTNAME/documents/DOCNAME/datastores/STOREID
     * @param mixed $data Data to store
     * @param bool $replace If true, replace entire store instead of merging
     * @return bool Success
     */
    function setDatastore($path, $data, $replace = false) {
        $path = trim($path, '/');

        $url_data = build_api_url($path);
        if ($url_data === null) {
            debug_print('datastores', "setDatastore() ERROR: Invalid path '$path'\n");
            return false;
        }

        // If not replacing, merge with existing data
        if (!$replace && is_array($data)) {
            $existing = getDatastore($path);
            if (is_array($existing)) {
                // Deep merge: flatten both, merge, reconstruct
                $flat_existing = array_flat($existing);
                $flat_new = array_flat($data);
                $flat_merged = array_merge($flat_existing, $flat_new);

                $data = [];
                foreach ($flat_merged as $keypath => $value) {
                    array_set($data, $keypath, $value);
                }
            }
        }

        // Convert to JSON if array
        $body = is_array($data) ? json_encode($data) : $data;

        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url_data['url']);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 5);
        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'PUT');
        curl_setopt($ch, CURLOPT_POSTFIELDS, $body);

        $headers = ['Content-Type: application/json'];
        if (strlen($url_data['pwd']) > 0) {
            $headers[] = 'Authorization: Bearer ' . $url_data['pwd'];
        }
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);

        $response = curl_exec($ch);
        $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        // curl_close($ch); // deprecated since PHP 8.0

        debug_print('datastores', "setDatastore() PUT $path → HTTP $http_code\n");

        return $http_code === 200;
    }

    /**
     * Delete a datastore (synchronous - not queued)
     *
     * @param string $path Full path: hosts/HOSTNAME/documents/DOCNAME/datastores/STOREID
     * @return bool Success
     */
    function deleteDatastore($path) {
        $path = trim($path, '/');

        $url_data = build_api_url($path);
        if ($url_data === null) {
            debug_print('datastores', "deleteDatastore() ERROR: Invalid path '$path'\n");
            return false;
        }

        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url_data['url']);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 5);
        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'DELETE');

        if (strlen($url_data['pwd']) > 0) {
            curl_setopt($ch, CURLOPT_HTTPHEADER, ['Authorization: Bearer ' . $url_data['pwd']]);
        }

        $response = curl_exec($ch);
        $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        // curl_close($ch); // deprecated since PHP 8.0

        debug_print('datastores', "deleteDatastore() DELETE $path → HTTP $http_code\n");

        return $http_code === 200;
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
            debug_print($namedAPI_path, "setVolume() ERROR: Unknown path type for $namedAPI_path\n");
            return;
        }

        debug_print($namedAPI_path, "setVolume() called: path=$namedAPI_path, property=$property, value=$value\n");
        setValue($namedAPI_path, [$property => $value]);
    }

    /**
     * Set appearance properties for a PIP Window (Video Placer) layer
     * Combines position, border, corner radius, shape and volume into one call
     */
    function setPIPWindowLayerAppearance($layer, $w, $h, $y, $x, $doc_path, $border_color, $border_width, $corner_radius, $volume) {
        setValue($layer, [
            'input-values' => [
                ...mimoPosition('tvGroup_Geometry__Window', $w, $h, $y, $x, $doc_path),
                'tvGroup_Appearance__Boarder_Color' => mimoColor($border_color),
                'tvGroup_Appearance__Boarder_Width_TypeBoinxY' => $border_width,
                'tvGroup_Appearance__Corner_Radius_TypeBoinxY' => $corner_radius,
                'tvGroup_Appearance__Shape' => 2
            ],
            'volume' => $volume
        ]);
    }

    function setAnimateVolume($namedAPI_path, $target_value, $steps=null, $fps=null) {
        $namedAPI_path=trim($namedAPI_path, '/');
        $namedAPI_path=trim($namedAPI_path);
        $namedAPI_path=trim($namedAPI_path, '/');
        global $current_frame, $configuration;

        // Get default framerate from configuration
        $default_framerate = array_get($configuration, 'framerate/master', default: 30);

        // If steps not provided, use framerate as default
        if ($steps === null) {
            $steps = $default_framerate;
        }

        // If FPS not provided, use framerate as default
        if ($fps === null) {
            $fps = $default_framerate;
        }

        $parts = explode('/', $namedAPI_path);

        // Determine audio property based on path type
        if (count($parts) == 4 && $parts[2] === 'documents') {
            $property = 'programOutputMasterVolume';
        } elseif (strpos($namedAPI_path, '/sources/') !== false) {
            $property = 'gain';
        } elseif (strpos($namedAPI_path, '/layers/') !== false) {
            $property = 'volume';
        } else {
            debug_print($namedAPI_path, "setAnimateVolume() ERROR: Unknown path type for $namedAPI_path\n");
            return;
        }

        // Get current volume value from namedAPI
        $current_value = namedAPI_get($namedAPI_path . '/' . $property, default: 0);

        debug_print($namedAPI_path, "setAnimateVolume() called: path=$namedAPI_path, from=$current_value, to=$target_value, steps=$steps, fps=$fps\n");

        // If already at target value, skip animation
        if (abs($current_value - $target_value) < 0.001) {
            debug_print($namedAPI_path, "  SKIPPED - already at target value\n");
            return;
        }

        // Calculate step increment
        $step_increment = ($target_value - $current_value) / $steps;

        // Calculate frame increment based on desired FPS
        $doc_fps = array_get($configuration, 'framerate/master', default: 30);
        $frame_increment = $doc_fps / $fps;

        // Queue actions for each step (including final target value)
        for ($i = 0; $i <= $steps; $i++) {
            $value = $current_value + ($step_increment * $i);
            $frame = $current_frame + (int)round($i * $frame_increment);

            queue_action($frame, $namedAPI_path, '', [$property => $value]);
            debug_print($namedAPI_path, "  Frame $frame: $property = $value\n");
        }
    }

    function setAnimateValue($namedAPI_path, $updates_array, $steps=null, $fps=null) {
        $namedAPI_path = trim($namedAPI_path, '/');
        $namedAPI_path = trim($namedAPI_path);
        $namedAPI_path = trim($namedAPI_path, '/');
        global $current_frame, $configuration;

        debug_print($namedAPI_path, "setAnimateValue() called: path=$namedAPI_path, updates=" . json_encode($updates_array) . ", steps=$steps, fps=$fps\n");

        // Validate that build_api_url works
        $url = build_api_url($namedAPI_path);
        if ($url === null) {
            debug_print($namedAPI_path, "  SKIPPED - build_api_url returned null\n");
            return;
        }

        // Get default framerate from configuration
        $default_framerate = array_get($configuration, 'framerate/master', default: 30);

        // If steps not provided, use framerate as default
        if ($steps === null) {
            $steps = $default_framerate;
        }

        // If FPS not provided, use framerate as default
        if ($fps === null) {
            $fps = $default_framerate;
        }

        // Calculate frame increment based on desired FPS
        $doc_fps = array_get($configuration, 'framerate/master', default: 30);
        $frame_increment = $doc_fps / $fps;

        // Process updates - handle colors specially (don't flatten them)
        $animations = [];

        // Recursive function to process updates
        $process_updates = function($array, $prefix = '') use (&$process_updates, &$animations, $namedAPI_path) {
            foreach ($array as $key => $value) {
                $current_path = $prefix ? $prefix . '/' . $key : $key;

                // Check if this looks like a color object (has red/green/blue/alpha keys)
                if (is_array($value) && isset($value['red'], $value['green'], $value['blue'], $value['alpha'])) {
                    // Treat as color value - don't flatten further
                    $current_value = namedAPI_get($namedAPI_path . '/' . $current_path);
                    $type_path = $namedAPI_path . '/' . str_replace('input-values/', 'input-descriptions/', $current_path) . '/type';
                    $type = namedAPI_get($type_path);

                    if ($type !== null && $current_value !== null) {
                        $animations[$current_path] = [
                            'current' => $current_value,
                            'target' => $value,
                            'type' => $type
                        ];
                        debug_print($namedAPI_path, "  $current_path: type=$type, from=" . json_encode($current_value) . ", to=" . json_encode($value) . "\n");
                    }
                } elseif (is_array($value)) {
                    // Recurse into nested array
                    $process_updates($value, $current_path);
                } else {
                    // Scalar value - process normally
                    $current_value = namedAPI_get($namedAPI_path . '/' . $current_path);
                    $type_path = $namedAPI_path . '/' . str_replace('input-values/', 'input-descriptions/', $current_path) . '/type';
                    $type = namedAPI_get($type_path);

                    if ($type !== null && $current_value !== null) {
                        $animations[$current_path] = [
                            'current' => $current_value,
                            'target' => $value,
                            'type' => $type
                        ];
                        debug_print($namedAPI_path, "  $current_path: type=$type, from=" . json_encode($current_value) . ", to=" . json_encode($value) . "\n");
                    } else {
                        if ($type === null) {
                            debug_print($namedAPI_path, "  WARNING: Could not determine type for $current_path\n");
                        }
                        if ($current_value === null) {
                            debug_print($namedAPI_path, "  WARNING: Could not get current value for $current_path\n");
                        }
                    }
                }
            }
        };

        $process_updates($updates_array);

        // Generate frames
        for ($i = 0; $i <= $steps; $i++) {
            $frame = $current_frame + (int)round($i * $frame_increment);
            $frame_payload = [];

            foreach ($animations as $keypath => $anim) {
                $type = $anim['type'];
                $current_value = $anim['current'];
                $target_value = $anim['target'];

                if ($type === 'number') {
                    // Check if already at target
                    if (abs($current_value - $target_value) < 0.001) {
                        $value = $target_value;
                    } else {
                        // Check if this is a wheel (angle in degrees)
                        // keypath = input-values/tvGroup_Appearance__Shadow_Direction
                        $desc_path = str_replace('input-values/', 'input-descriptions/', $keypath);
                        $unit_path = $namedAPI_path . '/' . $desc_path . '/value-unit';
                        $unit = namedAPI_get($unit_path);
                        $is_wheel = ($unit === '°' || $unit === "\u{00b0}");

                        if ($is_wheel) {
                            // Wheel animation - calculate shortest path
                            $min_path = $namedAPI_path . '/' . $desc_path . '/value-min';
                            $max_path = $namedAPI_path . '/' . $desc_path . '/value-max';
                            $min = namedAPI_get($min_path);
                            $max = namedAPI_get($max_path);

                            if ($min === null) $min = 0;
                            if ($max === null) $max = 360;

                            $range = $max - $min;

                            // Calculate shortest path for circular values
                            $diff = $target_value - $current_value;

                            // Normalize difference to -range/2 ... +range/2
                            while ($diff > $range / 2) $diff -= $range;
                            while ($diff < -$range / 2) $diff += $range;

                            $delta = $diff;

                            $step_increment = $delta / $steps;
                            $value = $current_value + ($step_increment * $i);

                            // Wrap around if needed
                            while ($value < $min) $value += $range;
                            while ($value >= $max) $value -= $range;
                        } else {
                            // Linear interpolation
                            $step_increment = ($target_value - $current_value) / $steps;
                            $value = $current_value + ($step_increment * $i);
                        }
                    }

                    array_set($frame_payload, $keypath, $value);

                } elseif ($type === 'color') {
                    // Interpolate RGBA components
                    if (is_array($current_value) && is_array($target_value)) {
                        $color = [];
                        foreach (['red', 'green', 'blue', 'alpha'] as $comp) {
                            if (isset($current_value[$comp]) && isset($target_value[$comp])) {
                                $increment = ($target_value[$comp] - $current_value[$comp]) / $steps;
                                $color[$comp] = $current_value[$comp] + ($increment * $i);
                            }
                        }
                        array_set($frame_payload, $keypath, $color);
                    }

                } else {
                    // Non-animatable types: just set on current frame
                    if ($i === 0) {
                        array_set($frame_payload, $keypath, $target_value);
                    }
                }
            }

            if (!empty($frame_payload)) {
                queue_action($frame, $namedAPI_path, '', $frame_payload);
                debug_print($namedAPI_path, "  Frame $frame: " . json_encode($frame_payload) . "\n", $frame);
            }
        }
    }

    // Internal Function
    function __increment_decrement($base, $var, $val, $operation) {
        $base = trim($base, '/');

        // Auto-detect: if var contains "__", it's an input-value
        if (strpos($var, '__') !== false) {
            $keypath = $base . '/input-values/' . $var;
        } else {
            $keypath = $base . '/' . $var;
        }

        debug_print($keypath, "$operation() called: base=$base, var=$var, val=$val -> keypath=$keypath\n");

        // Get current value
        $current_value = namedAPI_get($keypath);

        if ($current_value === null) {
            debug_print($keypath, "  ERROR: Could not get current value from $keypath\n");
            return;
        }

        // Check if it's a number
        if (!is_numeric($current_value)) {
            debug_print($keypath, "  ERROR: $operation() only works on numeric values (got " . gettype($current_value) . ")\n");
            return;
        }

        debug_print($keypath, "  Current value: $current_value\n");

        // Calculate new value
        if ($operation === 'increment') {
            $new_value = $current_value + $val;
        } else {
            $new_value = $current_value - $val;
        }

        // Check if this is an input-value (has input-descriptions)
        $is_input_value = strpos($var, '__') !== false;

        if ($is_input_value) {
            // Check if this is a wheel (angle in degrees)
            $unit_path = $base . '/input-descriptions/' . $var . '/value-unit';
            $unit = namedAPI_get($unit_path);
            $is_wheel = ($unit === '°' || $unit === "\u{00b0}");

            if ($is_wheel) {
                // Wheel: wrap around min/max
                $min_path = $base . '/input-descriptions/' . $var . '/value-min';
                $max_path = $base . '/input-descriptions/' . $var . '/value-max';
                $min = namedAPI_get($min_path);
                $max = namedAPI_get($max_path);

                if ($min === null) $min = 0;
                if ($max === null) $max = 360;

                $range = $max - $min;

                // Wrap around
                while ($new_value < $min) $new_value += $range;
                while ($new_value >= $max) $new_value -= $range;

                debug_print($keypath, "  Wheel: wrapped to $new_value (min=$min, max=$max)\n");
            } else {
                // Slider: clamp to min/max
                $min_path = $base . '/input-descriptions/' . $var . '/value-min';
                $max_path = $base . '/input-descriptions/' . $var . '/value-max';
                $min = namedAPI_get($min_path);
                $max = namedAPI_get($max_path);

                if ($min !== null && $new_value < $min) {
                    $new_value = $min;
                    debug_print($keypath, "  Clamped to min: $new_value\n");
                }
                if ($max !== null && $new_value > $max) {
                    $new_value = $max;
                    debug_print($keypath, "  Clamped to max: $new_value\n");
                }
            }

            debug_print($keypath, "  New value: $new_value\n");

            // Use setValue to update input-value
            setValue($base, ['input-values' => [$var => $new_value]]);
        } else {
            // Direct property (volume, gain, opacity, etc.) - no min/max checking
            debug_print($keypath, "  New value: $new_value\n");

            // Use setValue to update direct property
            setValue($base, [$var => $new_value]);
        }
    }

    function increment($base, $var, $val) {
        __increment_decrement($base, $var, $val, 'increment');
    }

    function decrement($base, $var, $val) {
        __increment_decrement($base, $var, $val, 'decrement');
    }

    function setAutoGrid($document_path, $gap, $color_default, $color_highlight, $top=0, $left=0, $bottom=0, $right=0, $threshold=-65.0, $audioTracking=true, $audioTrackingAutoSwitching=false) {
        $document_path = trim($document_path, '/');

        // Datastore path for autoGrid state
        $state_path = $document_path . '/datastores/autoGrid-state';

        // Load autoGrid structure from namedAPI (now includes precomputed data)
        $autoGrid = namedAPI_get($document_path . '/autoGrid');
        if ($autoGrid === null || empty($autoGrid)) {
            debug_print($document_path, "setAutoGrid: No autoGrid structure found");
            return ['delayed_off' => []];
        }

        // Get _meta (precomputed in build_namedAPI)
        $meta = $autoGrid['_meta'] ?? [];
        unset($autoGrid['_meta']); // Remove from iteration

        // Get document resolution from _meta
        $doc_width = $meta['doc_width'] ?? namedAPI_get($document_path . '/metadata/width');
        $doc_height = $meta['doc_height'] ?? namedAPI_get($document_path . '/metadata/height');

        // Sync video source from av_* to a_* (audio layers)
        // When av_* VideoSourceAImage changes, update a_* Audio input to match
        foreach ($autoGrid as $s_layer_name => $layer_info) {
            if (!is_array($layer_info)) continue;

            $audio_layer = $layer_info['audio'] ?? null;
            $audio_exists = $layer_info['audio_exists'] ?? false;

            if ($audio_exists) {
                // Use precomputed sources
                $video_source = $layer_info['video_source'] ?? null;
                $audio_source = $layer_info['audio_source'] ?? null;

                // Only sync if sources differ
                if ($video_source !== null && $video_source !== $audio_source) {
                    setValue($audio_layer, [
                        'input-values' => [
                            'tvGroup_Source__Audio_TypeAudio' => $video_source
                        ]
                    ]);
                }
            }
        }

        // Define working area (window) within document
        $work_left = $left;
        $work_top = $top;
        $work_width = $doc_width - $left - $right;
        $work_height = $doc_height - $top - $bottom;

        // Convert gap to pixels
        $gap_px = 0;
        if (is_string($gap) && str_ends_with($gap, '%')) {
            $gap_px = (min($work_width, $work_height) * (float)rtrim($gap, '%')) / 100.0;
        } else {
            $gap_px = (float)$gap;
        }

        // Determine corner radius and border width based on gap
        // Gap = 0 means fullscreen/seamless mode → no corner radius, no border
        // Gap > 0 means grid mode → corner radius and border
        $corner_radius = ($gap_px == 0) ? 0 : 0.05555556;
        $border_width_normal = ($gap_px == 0) ? 0.00 : 0.007407408;

        $delayed_off = [];

        // ===== PHASE 1: Status already precomputed in build_namedAPI =====
        // Status is now in $layer_info['status'], no need to iterate and lookup variants

        // ===== PHASE 2: Handle Exclusive Transitions =====
        // Collect all exclusive layers
        $exclusive_layers = [];
        foreach ($autoGrid as $s_layer => $info) {
            $status = $info['status'] ?? null;
            if ($status === 'exclusive') {
                $exclusive_layers[] = $s_layer;
            }
        }

        // Only do transitions if we have MORE THAN ONE exclusive
        if (count($exclusive_layers) > 1) {
            // Get last exclusive from datastore to find out which one is new
            $last_exclusive = getDatastore($state_path, 'lastExclusive');

            // Find the new exclusive (the one that's NOT the last one)
            $new_exclusive = null;
            foreach ($exclusive_layers as $ex_layer) {
                if ($ex_layer !== $last_exclusive) {
                    $new_exclusive = $ex_layer;
                    break;
                }
            }

            if ($new_exclusive) {
                // Transition old exclusive to video-and-audio
                if ($last_exclusive) {
                    setLive($document_path . '/layers/' . $last_exclusive . '/variants/video-and-audio');
                }

                // Transition all video-and-audio to audio-only
                foreach ($autoGrid as $s_layer => $info) {
                    if ($s_layer === $new_exclusive || $s_layer === $last_exclusive) continue;
                    $status = $info['status'] ?? null;

                    if ($status === 'video-and-audio') {
                        setLive($document_path . '/layers/' . $s_layer . '/variants/audio-only');
                    }
                }

                // Store new exclusive in datastore and return - NO calculations
                setDatastore($state_path, ['lastExclusive' => $new_exclusive]);
                return;
            }
        }

        // ===== PHASE 3: Berechnungen =====

        // Check if any exclusive status exists
        $has_exclusive = false;
        $exclusive_layer_info = null;
        foreach ($autoGrid as $s_layer => $info) {
            $status = $info['status'] ?? null;
            if ($status === 'exclusive') {
                $has_exclusive = true;
                $exclusive_layer_info = $info;
                $exclusive_layer_info['s_layer'] = $s_layer;
                break;
            }
        }

        if ($has_exclusive && $exclusive_layer_info) {
            // EXCLUSIVE MODE: One layer fullscreen, all others shrink to center
            $video_layer = $exclusive_layer_info['video'];
            $audio_layer = $exclusive_layer_info['audio'] ?? null;
            $audio_layer_exists = $exclusive_layer_info['audio_exists'] ?? false;

            debug_print($document_path, "EXCLUSIVE MODE: video=$video_layer, audio=$audio_layer, a_exists=" . ($audio_layer_exists ? 'YES' : 'NO') . "\n");

            // Volume logic: if a_* exists, av_* always gets volume=0
            $video_volume = $audio_layer_exists ? 0.0 : 1.0;

            // Exclusive layer takes FULL document (ignoring margins)
            setLive($video_layer);
            setPIPWindowLayerAppearance($video_layer, $doc_width, $doc_height, 0, 0, $document_path, $color_highlight, 0, 0, $video_volume);

            // Handle audio layer for exclusive (only if it exists)
            if ($audio_layer_exists) {
                debug_print($document_path, "EXCLUSIVE: setLive + volume=1.0 for $audio_layer\n");
                setLive($audio_layer);
                setValue($audio_layer, ['volume' => 1.0]);
            }

            // Shrink all other layers to center (size 0, border 0)
            $center_x = $work_left + ($work_width / 2);
            $center_y = $work_top + ($work_height / 2);

            foreach ($autoGrid as $s_layer => $info) {
                if (!is_array($info)) continue;
                $status = $info['status'] ?? null;
                $other_video_layer = $info['video'] ?? null;
                $other_audio_layer = $info['audio'] ?? null;
                $other_audio_exists = $info['audio_exists'] ?? false;

                if (!$other_video_layer || $status === 'exclusive') continue;

                // Volume logic: if a_* exists, av_* always gets volume=0
                if ($other_audio_exists) {
                    $other_video_volume = 0.0;
                } else {
                    // Keep audio as is based on status (audio-only gets volume, others don't)
                    $other_video_volume = ($status === 'audio-only') ? 1.0 : 0.0;
                }

                setLive($other_video_layer);
                setPIPWindowLayerAppearance($other_video_layer, 0, 0, $center_y, $center_x, $document_path, $color_default, 0, 0, $other_video_volume);

                // Handle audio layer for other positions (only if it exists)
                if ($other_audio_exists) {
                    setLive($other_audio_layer);
                    // Keep audio based on status
                    $other_audio_volume = ($status === 'video-and-audio' || $status === 'audio-only') ? 1.0 : 0.0;
                    setValue($other_audio_layer, ['volume' => $other_audio_volume]);
                }
            }

        }

        // Grid layout for video-and-audio and video-no-audio positions
        // Only if NO exclusive exists
        $has_exclusive = false;
        foreach ($autoGrid as $info) {
            if (($info['status'] ?? null) === 'exclusive') {
                $has_exclusive = true;
                break;
            }
        }

        if (!$has_exclusive) {
            // Check if presenter is VISIBLE (not just exists)
            $presenter_active = false;
            if (isset($autoGrid['s_av_presenter'])) {
                $presenter_status = $autoGrid['s_av_presenter']['status'] ?? null;
                if ($presenter_status === 'video-and-audio' || $presenter_status === 'video-no-audio' || $presenter_status === 'exclusive') {
                    $presenter_active = true;
                }
            }

            if ($presenter_active) {
                // PRESENTER MODE (within working area, aspect-ratio preserving)

                // Check which sides have visible positions
                $has_left_visible = false;
                $has_right_visible = false;
                $all_positions_temp = [];
                foreach ($autoGrid as $s_layer => $info) {
                    if ($s_layer === 's_av_presenter') continue;
                    $idx = count($all_positions_temp);
                    $all_positions_temp[] = $info;
                    $status = $info['status'] ?? null;
                    if ($status === 'video-and-audio' || $status === 'video-no-audio') {
                        $side = ($idx % 2 === 0) ? 'right' : 'left';
                        if ($side === 'right') $has_right_visible = true;
                        else $has_left_visible = true;
                    }
                }

                $doc_aspect = $doc_width / $doc_height;

                // Max tile size for calculating reserved space
                $max_tile_size = 200;
                $tile_reserve = $max_tile_size + (2 * $gap_px);

                if (!$has_left_visible && !$has_right_visible) {
                    // No visible positions - presenter takes full working area
                    $presenter_width = $work_width;
                    $presenter_height = $work_height;
                    $presenter_left = $work_left;
                    $presenter_top = $work_top;
                } else {
                    // Calculate reserved space for tiles (max 200px + gaps per occupied side)
                    $left_reserve = $has_left_visible ? $tile_reserve : 0;
                    $right_reserve = $has_right_visible ? $tile_reserve : 0;
                    $presenter_max_width = $work_width - $left_reserve - $right_reserve;
                    $presenter_max_height = $work_height;

                    // Fit presenter maintaining aspect ratio
                    $presenter_aspect = $doc_aspect;
                    if ($presenter_max_width / $presenter_max_height > $presenter_aspect) {
                        // Limited by height
                        $presenter_height = $presenter_max_height;
                        $presenter_width = $presenter_height * $presenter_aspect;
                    } else {
                        // Limited by width
                        $presenter_width = $presenter_max_width;
                        $presenter_height = $presenter_width / $presenter_aspect;
                    }

                    // Position presenter based on which sides have tiles
                    if ($has_left_visible && !$has_right_visible) {
                        // Only left has tiles - presenter shifts right
                        $presenter_left = $work_left + $left_reserve + (($work_width - $left_reserve - $presenter_width) / 2);
                    } elseif ($has_right_visible && !$has_left_visible) {
                        // Only right has tiles - presenter shifts left
                        $presenter_left = $work_left + (($work_width - $right_reserve - $presenter_width) / 2);
                    } else {
                        // Both sides have tiles - center presenter
                        $presenter_left = $work_left + $left_reserve + (($presenter_max_width - $presenter_width) / 2);
                    }
                    $presenter_top = $work_top + (($work_height - $presenter_height) / 2);
                }

                $presenter_video = $autoGrid['s_av_presenter']['video'];
                $presenter_audio = $autoGrid['s_av_presenter']['audio'] ?? null;
                $presenter_status = $autoGrid['s_av_presenter']['status'];
                $presenter_audio_exists = $autoGrid['s_av_presenter']['audio_exists'] ?? false;
                $presenter_audio_level = $autoGrid['s_av_presenter']['audio_level'] ?? null;

                // Calculate center point for presenter (for shrinking to size 0)
                $presenter_center_x = $presenter_left + ($presenter_width / 2);
                $presenter_center_y = $presenter_top + ($presenter_height / 2);

                // Determine size based on status
                if ($presenter_status === 'video-and-audio' || $presenter_status === 'video-no-audio' || $presenter_status === 'exclusive') {
                    // Visible: normal size
                    $final_width = $presenter_width;
                    $final_height = $presenter_height;
                    $final_left = $presenter_left;
                    $final_top = $presenter_top;
                    $border_width = $border_width_normal;
                } else {
                    // Invisible (off, audio-only): size 0 at center
                    $final_width = 0;
                    $final_height = 0;
                    $final_left = $presenter_center_x;
                    $final_top = $presenter_center_y;
                    $border_width = 0;
                }

                // Border color: only use highlight if a_* layer exists and audioTracking is enabled
                if ($audioTracking && $presenter_audio_exists && $border_width > 0) {
                    $is_speaking = ($presenter_audio_level !== null && (float)$presenter_audio_level != 0 && (float)$presenter_audio_level > $threshold);
                    $presenter_border_color = $is_speaking ? $color_highlight : $color_default;
                } else {
                    $presenter_border_color = $color_default;
                }

                // Volume logic: if a_* exists, av_* always gets volume=0
                $presenter_video_volume = $presenter_audio_exists ? 0.0 : (($presenter_status === 'video-and-audio' || $presenter_status === 'exclusive') ? 1.0 : 0.0);

                setLive($presenter_video);
                setPIPWindowLayerAppearance($presenter_video, $final_width, $final_height, $final_top, $final_left, $document_path, $presenter_border_color, $border_width, $corner_radius, $presenter_video_volume);

                if ($presenter_audio_exists) {
                    setLive($presenter_audio);
                    $audio_volume = ($presenter_status === 'video-and-audio' || $presenter_status === 'audio-only' || $presenter_status === 'exclusive') ? 1.0 : 0.0;
                    setValue($presenter_audio, ['volume' => $audio_volume]);
                }

                // Collect all positions (except presenter) for zig-zag layout
                $all_positions = [];
                foreach ($autoGrid as $s_layer => $info) {
                    if ($s_layer === 's_av_presenter') continue;
                    $all_positions[] = $info;
                }

                if (count($all_positions) > 0) {
                    // PHASE 1: First count visible positions by side
                    $left_visible = [];
                    $right_visible = [];

                    foreach ($all_positions as $idx => $info) {
                        $status = $info['status'] ?? null;
                        $is_visible = ($status === 'video-and-audio' || $status === 'video-no-audio');

                        if ($is_visible) {
                            $side = ($idx % 2 === 0) ? 'right' : 'left';
                            if ($side === 'right') {
                                $right_visible[] = $idx;
                            } else {
                                $left_visible[] = $idx;
                            }
                        }
                    }

                    $num_right = count($right_visible);
                    $num_left = count($left_visible);
                    $max_tiles_per_side = max($num_right, $num_left);

                    // PHASE 2: Calculate tile dimensions
                    // Available space on each side depends on how many sides are occupied
                    $num_occupied_sides = ($num_left > 0 ? 1 : 0) + ($num_right > 0 ? 1 : 0);
                    if ($num_occupied_sides === 1) {
                        // Only one side has tiles - it gets all the remaining space
                        $side_width = $work_width - $presenter_width - $gap_px;
                    } else {
                        // Both sides have tiles - split remaining space
                        $side_width = ($work_width - $presenter_width) / 2;
                    }

                    // Tile dimensions - MUST BE SQUARE
                    // Limited by BOTH available width AND available height
                    $max_tile_from_width = $side_width - (2 * $gap_px);

                    // Calculate max tile height based on how many tiles need to fit
                    if ($max_tiles_per_side > 0) {
                        $max_tile_from_height = ($work_height - (($max_tiles_per_side - 1) * $gap_px)) / $max_tiles_per_side;
                    } else {
                        $max_tile_from_height = $work_height;
                    }

                    // Use the SMALLER dimension to ensure tiles fit, max 200px
                    $tile_size = min($max_tile_from_width, $max_tile_from_height, 200);
                    $tile_width = $tile_size;
                    $tile_height = $tile_size;

                    // Calculate x positions within working area
                    $x_right = $work_left + $work_width - $gap_px - $tile_width;
                    $x_left = $work_left + $gap_px;

                    // Right side centering
                    $right_chain_height = ($num_right * $tile_height) + (($num_right - 1) * $gap_px);
                    $y_start_right = $work_top + (($work_height - $right_chain_height) / 2);

                    // Left side centering
                    $left_chain_height = ($num_left * $tile_height) + (($num_left - 1) * $gap_px);
                    $y_start_left = $work_top + (($work_height - $left_chain_height) / 2);

                    // Build position calculations
                    $position_calcs = [];
                    $right_counter = 0;
                    $left_counter = 0;

                    foreach ($all_positions as $idx => $position) {
                        $video_layer = $position['video'] ?? null;
                        $audio_layer = $position['audio'] ?? null;
                        $status = $position['status'] ?? null;
                        $audio_exists = $position['audio_exists'] ?? false;
                        $audio_level = $position['audio_level'] ?? null;
                        if (!$video_layer) continue;

                        $side = ($idx % 2 === 0) ? 'right' : 'left';
                        $is_visible = ($status === 'video-and-audio' || $status === 'video-no-audio');

                        if ($side === 'right') {
                            $x = $x_right;
                            $y_pos = $y_start_right + ($right_counter * ($tile_height + $gap_px));
                            if ($is_visible) $right_counter++;
                        } else {
                            $x = $x_left;
                            $y_pos = $y_start_left + ($left_counter * ($tile_height + $gap_px));
                            if ($is_visible) $left_counter++;
                        }

                        $width = $is_visible ? $tile_width : 0;
                        $height = $is_visible ? $tile_height : 0;

                        $position_calcs[] = [
                            'video_layer' => $video_layer,
                            'audio_layer' => $audio_layer,
                            'status' => $status,
                            'audio_exists' => $audio_exists,
                            'audio_level' => $audio_level,
                            'x' => $x,
                            'y' => $y_pos,
                            'width' => $width,
                            'height' => $height
                        ];
                    }

                    // PHASE 2: Apply all calculated positions
                    foreach ($position_calcs as $calc) {
                        $video_layer = $calc['video_layer'];
                        $audio_layer = $calc['audio_layer'] ?? null;
                        $status = $calc['status'];
                        $audio_layer_exists = $calc['audio_exists'] ?? false;
                        $audio_level = $calc['audio_level'] ?? null;
                        $x = $calc['x'];
                        $y = $calc['y'];
                        $width = $calc['width'];
                        $height = $calc['height'];

                        if ($status === 'video-and-audio' || $status === 'video-no-audio') {
                            // Border color: only use highlight if a_* layer exists and audioTracking is enabled
                            if ($audioTracking && $audio_layer_exists) {
                                $is_speaking = ($audio_level !== null && (float)$audio_level != 0 && (float)$audio_level > $threshold);
                                $border_color = $is_speaking ? $color_highlight : $color_default;
                            } else {
                                $border_color = $color_default;
                            }

                            // Volume logic: if a_* exists, av_* always gets volume=0
                            $video_volume = $audio_layer_exists ? 0.0 : (($status === 'video-and-audio') ? 1.0 : 0.0);

                            setLive($video_layer);
                            setPIPWindowLayerAppearance($video_layer, $width, $height, $y, $x, $document_path, $border_color, $border_width_normal, $corner_radius, $video_volume);

                            // Handle audio layer (only if it exists)
                            if ($audio_layer_exists) {
                                setLive($audio_layer);
                                setValue($audio_layer, ['volume' => ($status === 'video-and-audio') ? 1.0 : 0.0]);
                            }
                        } else {
                            // Non-visible states: exclude, off, audio-only
                            $video_live_state = namedAPI_get($video_layer . '/live-state');

                            // Volume logic for av_*: if a_* exists, av_* always gets volume=0
                            // Exception: audio-only without a_* layer gets volume=1.0 (audio comes from av_*)
                            if ($audio_layer_exists) {
                                $av_volume = 0.0;
                            } else {
                                $av_volume = ($status === 'audio-only') ? 1.0 : 0.0;
                            }

                            // Volume logic for a_*: audio-only gets volume=1.0, others get volume=0.0
                            $a_volume = ($status === 'audio-only') ? 1.0 : 0.0;

                            if ($status === 'exclude') {
                                if ($video_live_state === 'live') {
                                    setPIPWindowLayerAppearance($video_layer, $width, $height, $y, $x, $document_path, $color_default, 0, 0, $av_volume);
                                    $delayed_off[] = $video_layer;

                                    // Handle audio layer (only if it exists)
                                    if ($audio_layer_exists) {
                                        setValue($audio_layer, ['volume' => $a_volume]);
                                        $delayed_off[] = $audio_layer;
                                    }
                                }
                            } elseif ($status === 'off') {
                                setLive($video_layer);
                                setPIPWindowLayerAppearance($video_layer, $width, $height, $y, $x, $document_path, $color_default, 0, 0, $av_volume);

                                // Handle audio layer (only if it exists)
                                if ($audio_layer_exists) {
                                    setLive($audio_layer);
                                    setValue($audio_layer, ['volume' => $a_volume]);
                                }
                            } elseif ($status === 'audio-only') {
                                setLive($video_layer);
                                setPIPWindowLayerAppearance($video_layer, $width, $height, $y, $x, $document_path, $color_default, 0, 0, $av_volume);

                                // Handle audio layer (only if it exists)
                                if ($audio_layer_exists) {
                                    setLive($audio_layer);
                                    setValue($audio_layer, ['volume' => $a_volume]);
                                }
                            }
                        }
                    }
                }

            } else {
                // GROUPS MODE

                // Handle presenter if it exists but is not visible (off, audio-only, exclude)
                if (isset($autoGrid['s_av_presenter'])) {
                    $presenter_status = $autoGrid['s_av_presenter']['status'] ?? null;
                    if ($presenter_status === 'off' || $presenter_status === 'audio-only' || $presenter_status === 'exclude') {
                        $presenter_video = $autoGrid['s_av_presenter']['video'];
                        $presenter_audio = $autoGrid['s_av_presenter']['audio'] ?? null;
                        $presenter_audio_exists = $autoGrid['s_av_presenter']['audio_exists'] ?? false;

                        // Shrink to center of working area
                        $center_x = $work_left + ($work_width / 2);
                        $center_y = $work_top + ($work_height / 2);

                        // Volume logic for av_*: if a_* exists, av_* always gets volume=0
                        // Exception: audio-only without a_* layer gets volume=1.0
                        if ($presenter_audio_exists) {
                            $av_volume = 0.0;
                        } else {
                            $av_volume = ($presenter_status === 'audio-only') ? 1.0 : 0.0;
                        }

                        // Volume logic for a_*
                        $a_volume = ($presenter_status === 'audio-only') ? 1.0 : 0.0;

                        setLive($presenter_video);
                        setPIPWindowLayerAppearance($presenter_video, 0, 0, $center_y, $center_x, $document_path, $color_default, 0, 0, $av_volume);

                        // Handle audio layer (only if it exists)
                        if ($presenter_audio_exists) {
                            setLive($presenter_audio);
                            setValue($presenter_audio, ['volume' => $a_volume]);
                        }
                    }
                }

                // Organize ALL positions by group (including non-visible ones)
                $groups = [];
                $groups_all = []; // All layers including non-visible
                foreach ($autoGrid as $s_layer => $info) {
                    if ($s_layer === 's_av_presenter') continue;

                    $status = $info['status'] ?? null;
                    $group = $info['group'] ?? null;

                    if (!$group) continue;

                    // Collect ALL layers for this group
                    if (!isset($groups_all[$group])) {
                        $groups_all[$group] = [];
                    }
                    $groups_all[$group][] = $info;

                    // Collect only visible layers for grid calculation
                    if ($status === 'video-and-audio' || $status === 'video-no-audio') {
                        if (!isset($groups[$group])) {
                            $groups[$group] = [];
                        }
                        $groups[$group][] = $info;
                    }
                }

                // Number of groups WITH visible layers
                $num_groups = count($groups);
                if ($num_groups > 0) {
                    $aspect_ratio = $work_width / $work_height;

                    if ($aspect_ratio >= 1) {
                        // 16:9 or wider: side-by-side within working area
                        $group_width = ($work_width - ($num_groups - 1) * $gap_px) / $num_groups;
                        $group_height = $work_height;

                        $idx = 0;
                        foreach ($groups as $group_name => $visible_positions) {
                            $group_left = $work_left + ($idx * ($group_width + $gap_px));
                            $group_top = $work_top;

                            // Get all positions for this group (including non-visible)
                            $all_positions = $groups_all[$group_name] ?? [];

                            layoutGroupGrid($document_path, $all_positions, $visible_positions, $group_left, $group_top, $group_width, $group_height, $gap_px, $color_default, $color_highlight, $border_width_normal, $corner_radius, $threshold, $audioTracking);
                            $idx++;
                        }
                    } else {
                        // 9:16 or taller: stacked within working area
                        $group_width = $work_width;
                        $group_height = ($work_height - ($num_groups - 1) * $gap_px) / $num_groups;

                        $idx = 0;
                        foreach ($groups as $group_name => $visible_positions) {
                            $group_left = $work_left;
                            $group_top = $work_top + ($idx * ($group_height + $gap_px));

                            // Get all positions for this group (including non-visible)
                            $all_positions = $groups_all[$group_name] ?? [];

                            layoutGroupGrid($document_path, $all_positions, $visible_positions, $group_left, $group_top, $group_width, $group_height, $gap_px, $color_default, $color_highlight, $border_width_normal, $corner_radius, $threshold, $audioTracking);
                            $idx++;
                        }
                    }
                }

                // Handle groups WITHOUT any visible layers (shrink to center of working area)
                $center_x = $work_left + ($work_width / 2);
                $center_y = $work_top + ($work_height / 2);

                foreach ($groups_all as $group_name => $all_positions) {
                    // Skip groups that have visible layers (already handled above)
                    if (isset($groups[$group_name])) continue;

                    // This group has NO visible layers - shrink all to center
                    foreach ($all_positions as $position) {
                        $video_layer = $position['video'];
                        $audio_layer = $position['audio'] ?? null;
                        $status = $position['status'];
                        $audio_layer_exists = $position['audio_exists'] ?? false;

                        // Volume logic: if a_* exists, av_* always gets volume=0
                        $av_volume = $audio_layer_exists ? 0.0 : (($status === 'audio-only') ? 1.0 : 0.0);

                        setLive($video_layer);
                        setPIPWindowLayerAppearance($video_layer, 0, 0, $center_y, $center_x, $document_path, $color_default, 0, 0, $av_volume);

                        // Handle audio layer (only if it exists)
                        if ($audio_layer_exists) {
                            setLive($audio_layer);
                            setValue($audio_layer, ['volume' => ($status === 'audio-only') ? 1.0 : 0.0]);
                        }
                    }
                }
            }
        }

        // ===== PHASE 4: Handle delayed OFF =====
        // Execute setOff on next frame, but don't reload namedAPI between frames
        if (!empty($delayed_off)) {
            setSleep(0, false); // Execute frame 0, no reload
            foreach ($delayed_off as $layer) {
                setOff($layer);
            }
        }

        // Store current exclusive in datastore at the end (only if one exists)
        if (count($exclusive_layers) === 1) {
            setDatastore($state_path, ['lastExclusive' => $exclusive_layers[0]]);
        } elseif (count($exclusive_layers) === 0) {
            // No exclusive - clear the datastore
            deleteDatastore($state_path);
        }

        // ===== PHASE 5: Audio Tracking Auto-Switching =====
        // Auto-activate video when audio-only layer starts speaking
        // NOTE: Only switches ON (audio-only → video-and-audio), never OFF
        // NOTE: Presenter is excluded from auto-switching
        // NOTE: Disabled when any exclusive is active
        if ($audioTrackingAutoSwitching && !$has_exclusive) {
            foreach ($autoGrid as $s_layer_name => $layer_info) {
                if (!is_array($layer_info)) continue;
                // Skip presenter - no auto-switching for presenter
                if ($s_layer_name === 's_av_presenter') continue;

                $status = $layer_info['status'] ?? null;
                $audio_layer_exists = $layer_info['audio_exists'] ?? false;
                $audio_level = $layer_info['audio_level'] ?? null;

                // Only for audio-only AND when a_* layer exists
                if ($audio_layer_exists && $status === 'audio-only') {
                    // Check if speaking using precomputed audio_level
                    $is_speaking = ($audio_level !== null && (float)$audio_level != 0 && (float)$audio_level > $threshold);

                    if ($is_speaking) {
                        // Speaking → switch to video-and-audio
                        setLive($document_path . '/layers/' . $s_layer_name . '/variants/video-and-audio');
                    }
                }
            }
        }

    }

    function layoutGroupGrid($document_path, $all_positions, $visible_positions, $group_left, $group_top, $group_width, $group_height, $gap_px, $color_default, $color_highlight, $border_width_normal, $corner_radius, $threshold, $audioTracking) {
        $num_visible = count($visible_positions);
        $num_all = count($all_positions);
        if ($num_all === 0) return;

        // Special case: Only ONE visible layer -> takes full group area
        if ($num_visible === 1) {
            $center_x = $group_left + ($group_width / 2);
            $center_y = $group_top + ($group_height / 2);

            foreach ($all_positions as $position) {
                $video_layer = $position['video'];
                $audio_layer = $position['audio'] ?? null;
                $status = $position['status'];
                $audio_layer_exists = $position['audio_exists'] ?? false;
                $audio_level = $position['audio_level'] ?? null;

                if ($status === 'video-and-audio' || $status === 'video-no-audio') {
                    // Border color: only use highlight if a_* layer exists and audioTracking is enabled
                    if ($audioTracking && $audio_layer_exists) {
                        $is_speaking = ($audio_level !== null && (float)$audio_level != 0 && (float)$audio_level > $threshold);
                        $border_color = $is_speaking ? $color_highlight : $color_default;
                    } else {
                        $border_color = $color_default;
                    }

                    // Volume logic: if a_* exists, av_* always gets volume=0
                    $video_volume = $audio_layer_exists ? 0.0 : (($status === 'video-and-audio') ? 1.0 : 0.0);

                    // The ONE visible layer takes full group area
                    setLive($video_layer);
                    setPIPWindowLayerAppearance($video_layer, $group_width, $group_height, $group_top, $group_left, $document_path, $border_color, $border_width_normal, $corner_radius, $video_volume);

                    // Handle audio layer (only if it exists)
                    if ($audio_layer_exists) {
                        setLive($audio_layer);
                        setValue($audio_layer, ['volume' => ($status === 'video-and-audio') ? 1.0 : 0.0]);
                    }
                } else {
                    // Volume logic for non-visible: if a_* exists, av_* always gets volume=0
                    $video_volume = $audio_layer_exists ? 0.0 : (($status === 'audio-only') ? 1.0 : 0.0);

                    // Non-visible: shrink to center of group
                    setLive($video_layer);
                    setPIPWindowLayerAppearance($video_layer, 0, 0, $center_y, $center_x, $document_path, $color_default, 0, 0, $video_volume);

                    // Handle audio layer (only if it exists)
                    if ($audio_layer_exists) {
                        setLive($audio_layer);
                        setValue($audio_layer, ['volume' => ($status === 'audio-only') ? 1.0 : 0.0]);
                    }
                }
            }
            return;
        }

        // Calculate grid dimensions based on VISIBLE positions (expansion)
        $group_aspect = $group_width / $group_height;
        $base_cols = sqrt($num_visible * $group_aspect);
        $cols = max(1, round($base_cols));
        $rows = ceil($num_visible / $cols);

        $base_tile_width = ($group_width - ($cols - 1) * $gap_px) / $cols;
        $base_tile_height = ($group_height - ($rows - 1) * $gap_px) / $rows;

        // Position visible tiles (expanded to fill available space)
        $visible_idx = 0;
        foreach ($visible_positions as $position) {
            $col = $visible_idx % $cols;
            $row = floor($visible_idx / $cols);

            // Check if this is the last row
            $is_last_row = ($row === $rows - 1);
            $items_in_last_row = $num_visible - ($rows - 1) * $cols;

            // For last row: check if we need to expand width
            if ($is_last_row && $items_in_last_row < $cols) {
                // Last row has fewer items - expand them to fill width
                $tile_width = ($group_width - ($items_in_last_row - 1) * $gap_px) / $items_in_last_row;
                $x = $group_left + $col * ($tile_width + $gap_px);
            } else {
                $tile_width = $base_tile_width;
                $x = $group_left + $col * ($tile_width + $gap_px);
            }

            // Check if this column is in last column and last row is incomplete
            $is_last_col = ($col === $cols - 1);
            if ($is_last_col && $is_last_row && $items_in_last_row < $cols) {
                // Last item in incomplete row - expand height to fill remaining space
                $remaining_height = $group_height - ($row * ($base_tile_height + $gap_px));
                $tile_height = $remaining_height;
            } else {
                $tile_height = $base_tile_height;
            }

            $y = $group_top + $row * ($base_tile_height + $gap_px);

            $video_layer = $position['video'];
            $audio_layer = $position['audio'] ?? null;
            $status = $position['status'];
            $audio_layer_exists = $position['audio_exists'] ?? false;
            $audio_level = $position['audio_level'] ?? null;

            // Border color: only use highlight if a_* layer exists and audioTracking is enabled
            if ($audioTracking && $audio_layer_exists) {
                $is_speaking = ($audio_level !== null && (float)$audio_level != 0 && (float)$audio_level > $threshold);
                $border_color = $is_speaking ? $color_highlight : $color_default;
            } else {
                $border_color = $color_default;
            }

            // Volume logic: if a_* exists, av_* always gets volume=0
            $video_volume = $audio_layer_exists ? 0.0 : (($status === 'video-and-audio') ? 1.0 : 0.0);

            setLive($video_layer);
            setPIPWindowLayerAppearance($video_layer, $tile_width, $tile_height, $y, $x, $document_path, $border_color, $border_width_normal, $corner_radius, $video_volume);

            // Handle audio layer (only if it exists)
            if ($audio_layer_exists) {
                setLive($audio_layer);
                setValue($audio_layer, ['volume' => ($status === 'video-and-audio') ? 1.0 : 0.0]);
            }

            $visible_idx++;
        }

        // Calculate original grid for non-visible layers (to preserve relative positions)
        $orig_base_cols = sqrt($num_all * $group_aspect);
        $orig_cols = max(1, round($orig_base_cols));
        $orig_rows = ceil($num_all / $orig_cols);
        $orig_tile_width = ($group_width - ($orig_cols - 1) * $gap_px) / $orig_cols;
        $orig_tile_height = ($group_height - ($orig_rows - 1) * $gap_px) / $orig_rows;

        // Shrink non-visible layers to center of their original relative position
        foreach ($all_positions as $idx => $position) {
            $status = $position['status'];

            if ($status === 'video-and-audio' || $status === 'video-no-audio') {
                continue; // Already handled above
            }

            $col = $idx % $orig_cols;
            $row = floor($idx / $orig_cols);

            $x = $group_left + $col * ($orig_tile_width + $gap_px);
            $y = $group_top + $row * ($orig_tile_height + $gap_px);

            $center_x = $x + ($orig_tile_width / 2);
            $center_y = $y + ($orig_tile_height / 2);

            $video_layer = $position['video'];
            $audio_layer = $position['audio'] ?? null;
            $audio_layer_exists = $position['audio_exists'] ?? false;

            // Volume logic: if a_* exists, av_* always gets volume=0
            $video_volume = $audio_layer_exists ? 0.0 : (($status === 'audio-only') ? 1.0 : 0.0);

            setLive($video_layer);
            setPIPWindowLayerAppearance($video_layer, 0, 0, $center_y, $center_x, $document_path, $color_default, 0, 0, $video_volume);

            // Handle audio layer (only if it exists)
            if ($audio_layer_exists) {
                setLive($audio_layer);
                setValue($audio_layer, ['volume' => ($status === 'audio-only') ? 1.0 : 0.0]);
            }
        }
    }

    function getID($path) {
        $path = trim($path, '/');
        $path = trim($path);
        $path = trim($path, '/');

        debug_print($path, "getID() called: path=$path\n");
        $id = namedAPI_get($path . '/id');
        if ($id === null) {
            debug_print($path, "  WARNING: Path not found, returning none-source fallback\n");
            return '2124830483-com.mimolive.source.nonesource';
        }
        debug_print($path, "  Found ID: $id\n");
        return $id;
    }

    function mimoPosition($prefix, $width, $height, $top, $left, $namedAPI_path) {
        // Extract document path to get resolution
        $parts = explode('/', $namedAPI_path);
        if (count($parts) < 4 || $parts[2] !== 'documents') {
            debug_print($namedAPI_path, "mimoPosition() ERROR: Invalid path format - expected hosts/.../documents/...\n");
            return [];
        }

        $doc_path = implode('/', array_slice($parts, 0, 4));

        // Get document resolution
        $doc_width = namedAPI_get($doc_path . '/metadata/width');
        $doc_height = namedAPI_get($doc_path . '/metadata/height');

        if ($doc_width === null || $doc_height === null) {
            debug_print($namedAPI_path, "mimoPosition() ERROR: Could not get document resolution from $doc_path/metadata\n");
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

        debug_print($namedAPI_path, "mimoPosition(): doc_resolution={$doc_width}x{$doc_height}, requested={$width}x{$height} at top=$top, left=$left\n");

        // Calculate right and bottom distances from edges
        $right = $doc_width - $left - $width;
        $bottom = $doc_height - $top - $height;

        // Convert to mimoLive units (0-2 range, where 1 is center)
        $units_left = ($left / $doc_width) * 2;
        $units_top = ($top / $doc_height) * 2;
        $units_right = ($right / $doc_width) * 2;
        $units_bottom = ($bottom / $doc_height) * 2;

        debug_print($namedAPI_path, "  units: left=$units_left, top=$units_top, right=$units_right, bottom=$units_bottom\n");

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
                debug_print('mimoCrop', "mimoCrop() ERROR: namedAPI_path required when using pixel values\n");
                return [];
            }

            // Extract document path
            $parts = explode('/', $namedAPI_path);
            if (count($parts) < 4 || $parts[2] !== 'documents') {
                debug_print($namedAPI_path, "mimoCrop() ERROR: Invalid path format - expected hosts/.../documents/...\n");
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
                debug_print($namedAPI_path, "mimoCrop() ERROR: Could not determine SOURCE resolution\n");
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
                debug_print('mimoColor', "mimoColor() ERROR: Invalid hex color length: $color_string\n");
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

        debug_print('mimoColor', "mimoColor() ERROR: Unknown color format: $color_string\n");
        return ['red' => 0, 'green' => 0, 'blue' => 0, 'alpha' => 1];
    }

    function setSleep($frac_seconds, $reloadNamedAPI=true) {
        global $current_frame, $namedAPI, $configuration, $queue;

        // Get framerate from configuration (default to 30 if not set)
        $framerate = array_get($configuration, 'framerate/master', default: 30);
        $frame_duration = 1.0 / $framerate;

        // Find the highest frame number in the queue
        $max_queued_frame = $current_frame;
        if (!empty($queue)) {
            $max_queued_frame = max(array_keys($queue));
        }

        // Process all queued frames
        while ($current_frame <= $max_queued_frame) {
            process_current_frame();  // Send parallel curls, wait for ALL responses
            $current_frame++;

            // Sleep between frames (but not after the last frame)
            if ($current_frame <= $max_queued_frame) {
                wait($frame_duration);
            }
        }

        // After all frames are processed, sleep the requested duration
        if ($frac_seconds > 0) {
            wait($frac_seconds);
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
        setSleep($sleep, $reloadNamedAPI);
        exit();
    }

execute_user_script:
    eval($script);

    if ($background_mode===false) {
        echo json_encode($OUTPUT, JSON_PRETTY_PRINT);
    }