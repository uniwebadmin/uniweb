export type UniWebMode = 'test' | 'live';

export interface UniWebConfig {
  apiKey: string;
  apiSecret: string;
  mode?: UniWebMode;
  baseUrl?: string;
}

export interface ApiSuccessResponse {
  success: true;
  api_version: string;
  [key: string]: unknown;
}

export interface ApiErrorResponse {
  success: false;
  error_code: string;
  error: string;
  api_version?: string;
}

export interface CreatePaymentLinkParams {
  amount: number;
  description?: string;
  customer_phone?: string;
  customer_name?: string;
}

export interface CreateRefundParams {
  txn_id: string;
  amount?: number;
  reason?: string;
}

export interface ListTransactionsParams {
  from?: string;
  to?: string;
  limit?: number;
  offset?: number;
}

export interface WebhookEvent {
  id: string;
  event: string;
  created_at: string;
  data: Record<string, unknown>;
}
