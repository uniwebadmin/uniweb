<?php
declare(strict_types=1);

namespace UniWeb\Client\Exception;

/** Merchant API error with stable error_code from UniWeb. */
class ApiException extends UniWebException
{
    public function __construct(
        public readonly string $errorCode,
        string $message,
        public readonly int $httpStatus = 400,
        public readonly ?array $response = null,
    ) {
        parent::__construct($message, $httpStatus);
    }

    public static function fromResponse(int $httpStatus, array $body): self
    {
        $code = (string)($body['error_code'] ?? 'api_error');
        $message = (string)($body['error'] ?? 'Request could not be completed.');
        return new self($code, $message, $httpStatus, $body);
    }
}
