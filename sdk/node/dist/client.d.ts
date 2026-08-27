import type { ApiSuccessResponse, CreatePaymentLinkParams, CreateRefundParams, ListTransactionsParams, UniWebConfig } from './types.js';
export type FetchFn = typeof fetch;
export interface RequestCapture {
    url: string;
    body: string;
    headers: Record<string, string>;
}
export declare class Client {
    readonly mode: import('./types.js').UniWebMode;
    constructor(config: UniWebConfig, fetchFn?: FetchFn, capture?: {
        last?: RequestCapture;
    });
    createPaymentLink(params: CreatePaymentLinkParams, idempotencyKey?: string): Promise<ApiSuccessResponse>;
    checkStatus(txnId: string): Promise<ApiSuccessResponse>;
    createRefund(params: CreateRefundParams, idempotencyKey?: string): Promise<ApiSuccessResponse>;
    getBalance(): Promise<ApiSuccessResponse>;
    listTransactions(filters?: ListTransactionsParams): Promise<ApiSuccessResponse>;
    listRefunds(filters?: Record<string, unknown>): Promise<ApiSuccessResponse>;
    listPaymentLinks(filters?: Record<string, unknown>): Promise<ApiSuccessResponse>;
    getPaymentLink(linkId: string): Promise<ApiSuccessResponse>;
    request(body: Record<string, unknown>, idempotencyKey?: string): Promise<ApiSuccessResponse>;
}
