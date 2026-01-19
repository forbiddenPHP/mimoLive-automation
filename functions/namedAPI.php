<?php
/**
 * namedAPI.php
 *
 * Builds a complete name-based API wrapper for mimoLive
 * Converts UUID-based paths to human-readable named paths
 *
 * Example:
 *   UUID path: documents/2124830483/layers/47176E62-8286-4A51-9B53-B12FD0CFB7F5/variants/3CECD967-7BB8-43ED-8EE7-B610D32AFCC7
 *   Named path: documents/forbiddenPHP/layers/Comments/variants/Variant 1
 */

function buildNamedAPI($needs = null) {
    $baseUrl = 'http://localhost:8989/api/v1';
    $namedAPI = [];

    // If no needs specified, load everything (backward compatibility)
    if ($needs === null) {
        $needs = [
            'layers' => true,
            'layers_variants' => true,
            'layers_signals' => true,
            'sources' => true,
            'sources_filters' => true,
            'sources_signals' => true,
            'layer-sets' => true,
            'output-destinations' => true,
        ];
    }

    // Fetch all documents
    $documentsUrl = $baseUrl . '/documents';
    $documentsResponse = @file_get_contents($documentsUrl);

    if ($documentsResponse === false) {
        return ['error' => 'Could not connect to mimoLive API'];
    }

    $documentsData = json_decode($documentsResponse, true);

    foreach ($documentsData['data'] as $document) {
        $docId = $document['id'];
        $docName = $document['attributes']['name'];

        // Initialize document structure
        $namedAPI['documents'][$docName] = [
            'id' => $docId,
            'attributes' => $document['attributes'],
            'layers' => [],
            'sources' => [],
            'layer-sets' => [],
            'output-destinations' => []
        ];

        // Fetch layers (only if needed)
        if ($needs['layers']) {
            $layersUrl = $baseUrl . '/documents/' . $docId . '/layers';
            $layersResponse = @file_get_contents($layersUrl);

            if ($layersResponse !== false) {
                $layersData = json_decode($layersResponse, true);

                foreach ($layersData['data'] as $layer) {
                    $layerId = $layer['id'];
                    $layerName = $layer['attributes']['name'];

                    $namedAPI['documents'][$docName]['layers'][$layerName] = [
                        'id' => $layerId,
                        'attributes' => $layer['attributes'],
                        'variants' => [],
                        'signals' => $needs['layers_signals'] ? extractSignals($layer['attributes']['input-values'] ?? []) : []
                    ];

                    // Fetch variants for this layer (only if needed)
                    if ($needs['layers_variants']) {
                        $variantsUrl = $baseUrl . '/documents/' . $docId . '/layers/' . $layerId . '/variants';
                        $variantsResponse = @file_get_contents($variantsUrl);

                        if ($variantsResponse !== false) {
                            $variantsData = json_decode($variantsResponse, true);

                            if (isset($variantsData['data'])) {
                                foreach ($variantsData['data'] as $variant) {
                                    $variantId = $variant['id'];
                                    $variantName = $variant['attributes']['name'];

                                    $namedAPI['documents'][$docName]['layers'][$layerName]['variants'][$variantName] = [
                                        'id' => $variantId,
                                        'attributes' => $variant['attributes'],
                                        'signals' => $needs['layers_signals'] ? extractSignals($variant['attributes']['input-values'] ?? []) : []
                                    ];
                                }
                            }
                        }
                    }
                }
            }
        }

        // Fetch sources (only if needed)
        if ($needs['sources']) {
            $sourcesUrl = $baseUrl . '/documents/' . $docId . '/sources';
            $sourcesResponse = @file_get_contents($sourcesUrl);

            if ($sourcesResponse !== false) {
                $sourcesData = json_decode($sourcesResponse, true);

                foreach ($sourcesData['data'] as $source) {
                    $sourceId = $source['id'];
                    $sourceName = $source['attributes']['name'];

                    $namedAPI['documents'][$docName]['sources'][$sourceName] = [
                        'id' => $sourceId,
                        'attributes' => $source['attributes'],
                        'filters' => [],
                        'signals' => $needs['sources_signals'] ? extractSignals($source['attributes']['input-values'] ?? []) : []
                    ];

                    // Fetch filters for this source (only if needed)
                    if ($needs['sources_filters']) {
                        $filtersUrl = $baseUrl . '/documents/' . $docId . '/sources/' . $sourceId . '/filters';
                        $filtersResponse = @file_get_contents($filtersUrl);

                        if ($filtersResponse !== false) {
                            $filtersData = json_decode($filtersResponse, true);

                            if (isset($filtersData['data'])) {
                                foreach ($filtersData['data'] as $filter) {
                                    $filterId = $filter['id'];
                                    $filterName = $filter['attributes']['name'];

                                    $namedAPI['documents'][$docName]['sources'][$sourceName]['filters'][$filterName] = [
                                        'id' => $filterId,
                                        'attributes' => $filter['attributes'],
                                        'signals' => $needs['sources_signals'] ? extractSignals($filter['attributes']['input-values'] ?? []) : []
                                    ];
                                }
                            }
                        }
                    }
                }
            }
        }

        // Fetch layer-sets (only if needed)
        if ($needs['layer-sets']) {
            $layerSetsUrl = $baseUrl . '/documents/' . $docId . '/layer-sets';
            $layerSetsResponse = @file_get_contents($layerSetsUrl);

            if ($layerSetsResponse !== false) {
                $layerSetsData = json_decode($layerSetsResponse, true);

                foreach ($layerSetsData['data'] as $layerSet) {
                    $layerSetId = $layerSet['id'];
                    $layerSetName = $layerSet['attributes']['name'];

                    $namedAPI['documents'][$docName]['layer-sets'][$layerSetName] = [
                        'id' => $layerSetId,
                        'attributes' => $layerSet['attributes']
                    ];
                }
            }
        }

        // Fetch output-destinations (only if needed)
        if ($needs['output-destinations']) {
            $outputsUrl = $baseUrl . '/documents/' . $docId . '/output-destinations';
            $outputsResponse = @file_get_contents($outputsUrl);

            if ($outputsResponse !== false) {
                $outputsData = json_decode($outputsResponse, true);

                foreach ($outputsData['data'] as $output) {
                    $outputId = $output['id'];
                    $outputTitle = $output['attributes']['title'];

                    $namedAPI['documents'][$docName]['output-destinations'][$outputTitle] = [
                        'id' => $outputId,
                        'attributes' => $output['attributes']
                    ];
                }
            }
        }
    }

    return $namedAPI;
}

/**
 * Resolve a named path to UUIDs
 *
 * @param string $path Named path like "documents/forbiddenPHP/layers/Comments/variants/Variant 1"
 * @param array $namedAPI The named API structure
 * @return array|null Array with resolved IDs or null if not found
 */
function resolveNamedPath($path, $namedAPI) {
    $parts = explode('/', trim($path, '/'));
    $result = [];
    $current = $namedAPI;

    for ($i = 0; $i < count($parts); $i += 2) {
        $type = $parts[$i];
        $name = $parts[$i + 1] ?? null;

        if ($name === null) {
            return null;
        }

        if (!isset($current[$type][$name])) {
            return null;
        }

        $result[$type] = $current[$type][$name]['id'];
        $current = $current[$type][$name];
    }

    return $result;
}

/**
 * Build a UUID-based API path from a named path
 *
 * @param string $path Named path
 * @param array $namedAPI The named API structure
 * @return string|null UUID-based path or null if not found
 */
function buildUUIDPath($path, $namedAPI) {
    $resolved = resolveNamedPath($path, $namedAPI);

    if ($resolved === null) {
        return null;
    }

    $parts = explode('/', trim($path, '/'));
    $uuidPath = '';

    for ($i = 0; $i < count($parts); $i += 2) {
        $type = $parts[$i];

        if (isset($resolved[$type])) {
            $uuidPath .= '/' . $type . '/' . $resolved[$type];
        }
    }

    return ltrim($uuidPath, '/');
}

/**
 * Extract and normalize signals from input-values
 *
 * Converts signal keys like "tvGroup_Control__Cut_1_TypeSignal" to user-friendly names like "cut1"
 *
 * @param array $inputValues The input-values array from attributes
 * @return array Array of normalized signal names mapped to real signal IDs
 */
function extractSignals($inputValues) {
    if (!is_array($inputValues)) {
        return [];
    }

    $signals = [];

    foreach ($inputValues as $key => $value) {
        // Check if this is a signal (ends with _TypeSignal)
        if (strpos($key, '_TypeSignal') !== false) {
            // Extract the part between __ and _TypeSignal
            // Example: tvGroup_Control__Cut_1_TypeSignal -> Cut_1
            if (preg_match('/__(.+?)_TypeSignal$/', $key, $matches)) {
                $signalPart = $matches[1]; // e.g., "Cut_1" or "Dis_2" or "Cut_Below"

                // Normalize: remove underscores, hyphens, spaces, lowercase
                $normalized = strtolower(str_replace(['_', '-', ' '], '', $signalPart));

                $signals[$normalized] = [
                    'real-signal-id' => $key,
                    'display-name' => str_replace('_', ' ', $signalPart)
                ];
            }
        }
    }

    return $signals;
}
