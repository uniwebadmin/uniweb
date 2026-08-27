<?php
declare(strict_types=1);

namespace UniWeb\Client;

final class ClientConfig
{
    public const MODE_TEST = 'test';
    public const MODE_LIVE = 'live';

    public readonly string $baseUrl;

    public function __construct(
        public readonly string $apiKey,
        public readonly string $apiSecret,
        public readonly string $mode = self::MODE_TEST,
        string $baseUrl = 'https://uniweb.co.in/api/v1/',
    ) {
        if ($this->apiKey === '' || $this->apiSecret === '') {
            throw new \InvalidArgumentException('apiKey and apiSecret are required.');
        }
        if (!in_array($this->mode, [self::MODE_TEST, self::MODE_LIVE], true)) {
            throw new \InvalidArgumentException('mode must be test or live.');
        }
        if ($this->mode === self::MODE_TEST && !str_starts_with($this->apiKey, 'uw_test_')) {
            throw new \InvalidArgumentException('Test mode requires a uw_test_ API key.');
        }
        if ($this->mode === self::MODE_LIVE && !str_starts_with($this->apiKey, 'uw_live_')) {
            throw new \InvalidArgumentException('Live mode requires a uw_live_ API key.');
        }
        $this->baseUrl = rtrim($baseUrl, '/') . '/';
    }
}
