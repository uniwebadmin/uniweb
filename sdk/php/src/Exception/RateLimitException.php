<?php
declare(strict_types=1);

namespace UniWeb\Client\Exception;

final class RateLimitException extends ApiException
{
    public function __construct(
        string $errorCode,
        string $message,
        int $httpStatus,
        public readonly ?int $retryAfterSeconds,
        ?array $response = null,
    ) {
        parent::__construct($errorCode, $message, $httpStatus, $response);
    }
}
