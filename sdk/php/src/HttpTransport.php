<?php
declare(strict_types=1);

namespace UniWeb\Client;

interface HttpTransport
{
    /**
     * @param list<string> $headers
     * @return array{status:int, body:string, headers:array<string,string>}
     */
    public function post(string $url, string $body, array $headers): array;
}
