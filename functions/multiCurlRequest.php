<?php
    function multiCurlRequest(array $requests): array
    {
        $multiHandle = curl_multi_init();
        $curlHandles = [];
        $responses   = [];

        foreach ($requests as $key => $req) {

            $method  = strtoupper($req['method'] ?? 'GET');
            $url     = $req['url'];
            $data    = $req['data'] ?? [];
            $headers = $req['headers'] ?? [];
            $timeout = $req['timeout'] ?? 10;

            // GET → Query String anhängen
            if ($method === 'GET' && !empty($data)) {
                $query = http_build_query($data);
                $url  .= (str_contains($url, '?') ? '&' : '?') . $query;
            }

            $ch = curl_init($url);

            // Default headers für JSON
            if (empty($headers) && in_array($method, ['POST', 'PUT', 'PATCH'])) {
                $headers[] = 'Content-Type: application/json';
            }

            $options = [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_FOLLOWLOCATION => true,
                CURLOPT_TIMEOUT        => $timeout,
                CURLOPT_CUSTOMREQUEST  => $method,
                CURLOPT_HTTPHEADER     => $headers,
            ];

            // POST/PUT/PATCH Daten setzen
            if (in_array($method, ['POST', 'PUT', 'PATCH']) && !empty($data)) {
                $options[CURLOPT_POSTFIELDS] = is_array($data)
                    ? json_encode($data)
                    : $data;
            }

            curl_setopt_array($ch, $options);

            curl_multi_add_handle($multiHandle, $ch);
            $curlHandles[$key] = $ch;
        }

        // Requests ausführen
        $running = null;
        do {
            curl_multi_exec($multiHandle, $running);
            curl_multi_select($multiHandle);
        } while ($running > 0);

        // Ergebnisse sammeln
        foreach ($curlHandles as $key => $ch) {
            $responses[$key] = [
                'body'   => json_decode(curl_multi_getcontent($ch), true),
                'status' => curl_getinfo($ch, CURLINFO_HTTP_CODE),
                'error'  => curl_error($ch),
            ];

            curl_multi_remove_handle($multiHandle, $ch);
            // curl_close($ch);
        }

        curl_multi_close($multiHandle);

        return $responses;
    }