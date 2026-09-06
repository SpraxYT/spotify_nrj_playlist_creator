<?php

declare(strict_types=1);

/**
 * Requêtes HTTP : cURL si dispo, sinon file_get_contents.
 */
final class Http
{
    /**
     * @param array<string, string> $headers
     */
    public static function get(string $url, array $headers = [], int $timeout = 20): string
    {
        if (function_exists('curl_init')) {
            return self::curl('GET', $url, null, $headers, $timeout);
        }
        return self::stream('GET', $url, null, $headers, $timeout);
    }

    /**
     * @param array<string, string> $headers
     */
    public static function post(
        string $url,
        string $body,
        array $headers = [],
        int $timeout = 20
    ): string {
        if (function_exists('curl_init')) {
            return self::curl('POST', $url, $body, $headers, $timeout);
        }
        return self::stream('POST', $url, $body, $headers, $timeout);
    }

    /**
     * @param array<string, string> $headers
     * @return array{status:int, body:string}
     */
    public static function request(
        string $method,
        string $url,
        ?string $body = null,
        array $headers = [],
        int $timeout = 20
    ): array {
        if (function_exists('curl_init')) {
            return self::curlRaw($method, $url, $body, $headers, $timeout);
        }
        $raw = self::stream($method, $url, $body, $headers, $timeout, true);
        return $raw;
    }

    /**
     * @param array<string, string> $headers
     */
    private static function curl(
        string $method,
        string $url,
        ?string $body,
        array $headers,
        int $timeout
    ): string {
        $res = self::curlRaw($method, $url, $body, $headers, $timeout);
        if ($res['status'] < 200 || $res['status'] >= 300) {
            throw new RuntimeException(
                'HTTP ' . $res['status'] . ' : ' . substr($res['body'], 0, 200)
            );
        }
        return $res['body'];
    }

    /**
     * @param array<string, string> $headers
     * @return array{status:int, body:string}
     */
    private static function curlRaw(
        string $method,
        string $url,
        ?string $body,
        array $headers,
        int $timeout
    ): array {
        $ch = curl_init($url);
        if ($ch === false) {
            throw new RuntimeException('Impossible d’initialiser cURL');
        }

        $hdr = [];
        foreach ($headers as $k => $v) {
            $hdr[] = $k . ': ' . $v;
        }

        $opts = [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_TIMEOUT        => $timeout,
            CURLOPT_CUSTOMREQUEST  => strtoupper($method),
            CURLOPT_HTTPHEADER     => $hdr,
        ];
        if ($body !== null) {
            $opts[CURLOPT_POSTFIELDS] = $body;
        }
        curl_setopt_array($ch, $opts);

        $response = curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err = curl_error($ch);
        curl_close($ch);

        if ($response === false) {
            throw new RuntimeException('Échec HTTP : ' . $err);
        }

        return ['status' => $status, 'body' => (string) $response];
    }

    /**
     * @param array<string, string> $headers
     * @return ($withStatus is true ? array{status:int, body:string} : string)
     */
    private static function stream(
        string $method,
        string $url,
        ?string $body,
        array $headers,
        int $timeout,
        bool $withStatus = false
    ): string|array {
        $hdrLines = '';
        foreach ($headers as $k => $v) {
            $hdrLines .= $k . ': ' . $v . "\r\n";
        }

        $opts = [
            'http' => [
                'method'        => strtoupper($method),
                'header'        => $hdrLines,
                'timeout'       => $timeout,
                'ignore_errors' => true,
                'follow_location' => 1,
            ],
            'ssl' => [
                'verify_peer'      => true,
                'verify_peer_name' => true,
            ],
        ];
        if ($body !== null) {
            $opts['http']['content'] = $body;
        }

        $ctx = stream_context_create($opts);
        $response = @file_get_contents($url, false, $ctx);
        if ($response === false) {
            throw new RuntimeException('Échec HTTP (stream) vers ' . $url);
        }

        $status = 0;
        if (isset($http_response_header[0])
            && preg_match('/\s(\d{3})\s/', $http_response_header[0], $m)
        ) {
            $status = (int) $m[1];
        }

        if ($withStatus) {
            return ['status' => $status, 'body' => $response];
        }

        if ($status !== 0 && ($status < 200 || $status >= 300)) {
            throw new RuntimeException(
                'HTTP ' . $status . ' : ' . substr($response, 0, 200)
            );
        }

        return $response;
    }
}
