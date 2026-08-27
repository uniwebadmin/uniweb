import type { ApiErrorResponse } from './types.js';

export class UniWebError extends Error {
  constructor(
    message: string,
    public readonly errorCode: string = 'api_error',
    public readonly httpStatus: number = 500,
    public readonly response?: ApiErrorResponse,
  ) {
    super(message);
    this.name = 'UniWebError';
  }

  static fromResponse(status: number, body: ApiErrorResponse): UniWebError {
    return new UniWebError(body.error ?? 'Request could not be completed.', body.error_code ?? 'api_error', status, body);
  }
}

export class AuthenticationError extends UniWebError {
  constructor(errorCode: string, message: string, httpStatus: number, response?: ApiErrorResponse) {
    super(message, errorCode, httpStatus, response);
    this.name = 'AuthenticationError';
  }
}

export class RateLimitError extends UniWebError {
  constructor(
    errorCode: string,
    message: string,
    httpStatus: number,
    public readonly retryAfterSeconds: number | null,
    response?: ApiErrorResponse,
  ) {
    super(message, errorCode, httpStatus, response);
    this.name = 'RateLimitError';
  }
}
