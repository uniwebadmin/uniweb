<?php
declare(strict_types=1);

namespace UniWeb\Client;

final class CurlTransport implements HttpTransport
{
    public function post(string $url, string $body, array $headers): array
    {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $body,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HEADER => true,
            CURLOPT_TIMEOUT => 30,
            CURLOPT_CONNECTTIMEOUT => 10,
        ]);
        $raw = curl_exec($ch);
        if ($raw === false) {
            $err = curl_error($ch);
            curl_close($ch);
            throw new \RuntimeException('HTTP request failed: ' . $err);
        }
        $status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $headerSize = (int)curl_getinfo($ch, CURLINFO_HEADER_SIZE);
        curl_close($ch);

        $rawHeaders = substr($raw, 0, $headerSize);
        $responseBody = substr($raw, $headerSize) ?: '';

        return [
            'status' => $status,
            'body' => $responseBody,
            'headers' => self::parseHeaders($rawHeaders),
        ];
    }

    /** @return array<string,string> lower-case header names */
    private static function parseHeaders(string $raw): array
    {
        $out = [];
        foreach (preg_split('/\r\n|\n|\r/', $raw) ?: [] as $line) {
            if (!str_contains($line, ':')) {
                continue;
            }
            [$name, $value] = explode(':', $line, 2);
            $out[strtolower(trim($name))] = trim($value);
        }
        return $out;
    }
}
