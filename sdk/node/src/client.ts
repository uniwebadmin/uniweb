import { randomUUID } from 'node:crypto';
import { AuthenticationError, RateLimitError, UniWebError } from './errors.js';
import type {
  ApiErrorResponse,
  ApiSuccessResponse,
  CreatePaymentLinkParams,
  CreateRefundParams,
  ListTransactionsParams,
  UniWebConfig,
  UniWebMode,
} from './types.js';

const DEFAULT_BASE = 'https://uniweb.co.in/api/v1/';
const USER_AGENT = 'UniWeb-Node-SDK/1.0';
const WRITE_ACTIONS = new Set(['create_payment_link', 'create_refund']);

export type FetchFn = typeof fetch;

export interface RequestCapture {
  url: string;
  body: string;
  headers: Record<string, string>;
}

export class Client {
  private readonly apiKey: string;
  private readonly apiSecret: string;
  private readonly baseUrl: string;
  readonly mode: UniWebMode;

  constructor(
    config: UniWebConfig,
    private readonly fetchFn: FetchFn = fetch,
    private readonly capture?: { last?: RequestCapture },
  ) {
    if (!config.apiKey || !config.apiSecret) {
      throw new Error('apiKey and apiSecret are required.');
    }
    this.mode = config.mode ?? 'test';
    if (this.mode === 'test' && !config.apiKey.startsWith('uw_test_')) {
      throw new Error('Test mode requires a uw_test_ API key.');
    }
    if (this.mode === 'live' && !config.apiKey.startsWith('uw_live_')) {
      throw new Error('Live mode requires a uw_live_ API key.');
    }
    this.apiKey = config.apiKey;
    this.apiSecret = config.apiSecret;
    this.baseUrl = (config.baseUrl ?? DEFAULT_BASE).replace(/\/?$/, '/');
  }

  createPaymentLink(params: CreatePaymentLinkParams, idempotencyKey?: string): Promise<ApiSuccessResponse> {
    return this.request({ ...params, action: 'create_payment_link' }, idempotencyKey ?? this.newIdempotencyKey('link'));
  }

  checkStatus(txnId: string): Promise<ApiSuccessResponse> {
    return this.request({ action: 'check_status', txn_id: txnId });
  }

  createRefund(params: CreateRefundParams, idempotencyKey?: string): Promise<ApiSuccessResponse> {
    return this.request({ ...params, action: 'create_refund' }, idempotencyKey ?? this.newIdempotencyKey('refund'));
  }

  getBalance(): Promise<ApiSuccessResponse> {
    return this.request({ action: 'get_balance' });
  }

  listTransactions(filters: ListTransactionsParams = {}): Promise<ApiSuccessResponse> {
    return this.request({ action: 'list_transactions', ...filters });
  }

  listRefunds(filters: Record<string, unknown> = {}): Promise<ApiSuccessResponse> {
    return this.request({ action: 'list_refunds', ...filters });
  }

  listPaymentLinks(filters: Record<string, unknown> = {}): Promise<ApiSuccessResponse> {
    return this.request({ action: 'list_payment_links', ...filters });
  }

  getPaymentLink(linkId: string): Promise<ApiSuccessResponse> {
    return this.request({ action: 'get_payment_link', link_id: linkId });
  }

  async request(body: Record<string, unknown>, idempotencyKey?: string): Promise<ApiSuccessResponse> {
    const action = String(body.action ?? '');
    if (WRITE_ACTIONS.has(action) && !idempotencyKey?.trim()) {
      throw new Error(`Idempotency-Key is required for ${action}`);
    }

    const payload = JSON.stringify(body);
    const headers: Record<string, string> = {
      'Content-Type': 'application/json',
      Accept: 'application/json',
      'X-API-Key': this.apiKey,
      'X-API-Secret': this.apiSecret,
      'User-Agent': USER_AGENT,
    };
    if (idempotencyKey) {
      headers['Idempotency-Key'] = idempotencyKey;
    }

    if (this.capture) {
      this.capture.last = { url: this.baseUrl, body: payload, headers: { ...headers } };
    }

    const res = await this.fetchFn(this.baseUrl, { method: 'POST', headers, body: payload });
    const text = await res.text();
    let decoded: ApiSuccessResponse | ApiErrorResponse;
    try {
      decoded = JSON.parse(text) as ApiSuccessResponse | ApiErrorResponse;
    } catch {
      throw new UniWebError('UniWeb returned a non-JSON response.', 'invalid_response', res.status);
    }

    if (decoded.success === true) {
      return decoded;
    }

    const err = decoded as ApiErrorResponse;
    if (res.status === 429 || err.error_code === 'rate_limited') {
      const retry = res.headers.get('retry-after');
      throw new RateLimitError(err.error_code, err.error, res.status, retry ? parseInt(retry, 10) : null, err);
    }
    if (res.status === 401 || err.error_code === 'missing_credentials' || err.error_code === 'auth_failed') {
      throw new AuthenticationError(err.error_code, err.error, res.status, err);
    }
    throw UniWebError.fromResponse(res.status, err);
  }

  private newIdempotencyKey(prefix: string): string {
    return `${prefix}-${randomUUID()}`;
  }
}
