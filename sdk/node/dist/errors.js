export class UniWebError extends Error {
    constructor(message, errorCode = 'api_error', httpStatus = 500, response) {
        super(message);
        this.errorCode = errorCode;
        this.httpStatus = httpStatus;
        this.response = response;
        this.name = 'UniWebError';
    }
    static fromResponse(status, body) {
        return new UniWebError(body.error ?? 'Request could not be completed.', body.error_code ?? 'api_error', status, body);
    }
}
export class AuthenticationError extends UniWebError {
    constructor(errorCode, message, httpStatus, response) {
        super(message, errorCode, httpStatus, response);
        this.name = 'AuthenticationError';
    }
}
export class RateLimitError extends UniWebError {
    constructor(errorCode, message, httpStatus, retryAfterSeconds, response) {
        super(message, errorCode, httpStatus, response);
        this.retryAfterSeconds = retryAfterSeconds;
        this.name = 'RateLimitError';
    }
}
