import type { ApiErrorResponse } from './types.js';
export declare class UniWebError extends Error {
    readonly errorCode: string;
    readonly httpStatus: number;
    readonly response?: ApiErrorResponse;
    constructor(message: string, errorCode?: string, httpStatus?: number, response?: ApiErrorResponse);
    static fromResponse(status: number, body: ApiErrorResponse): UniWebError;
}
export declare class AuthenticationError extends UniWebError {
    constructor(errorCode: string, message: string, httpStatus: number, response?: ApiErrorResponse);
}
export declare class RateLimitError extends UniWebError {
    readonly retryAfterSeconds: number | null;
    constructor(errorCode: string, message: string, httpStatus: number, retryAfterSeconds: number | null, response?: ApiErrorResponse);
}
