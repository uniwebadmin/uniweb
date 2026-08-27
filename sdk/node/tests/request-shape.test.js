import assert from 'node:assert/strict';
import { Client } from '../dist/client.js';
import { verifySignature } from '../dist/webhook.js';

const capture = {};
const mockFetch = async (url, init) => {
  capture.last = {
    url: String(url),
    body: String(init?.body ?? ''),
    headers: Object.fromEntries(Object.entries(init?.headers ?? {})),
  };
  return new Response(JSON.stringify({ success: true, api_version: 'v1', link_id: 'LNKTEST' }), {
    status: 200,
    headers: { 'Content-Type': 'application/json' },
  });
};

const client = new Client(
  {
    apiKey: 'uw_test_shape_key',
    apiSecret: 'uws_shape_secret',
    mode: 'test',
    baseUrl: 'https://uniweb.co.in/api/v1/',
  },
  mockFetch,
  capture,
);

await client.createPaymentLink({ amount: 100, description: 'Shape test' });

assert.ok(capture.last.url.endsWith('/api/v1/'), 'url ends with /api/v1/');
const body = JSON.parse(capture.last.body);
assert.equal(body.action, 'create_payment_link');
assert.equal(body.amount, 100);
assert.ok(capture.last.headers['X-API-Key'].startsWith('uw_test_'));
assert.ok(capture.last.headers['X-API-Secret']);
assert.ok(capture.last.headers['Idempotency-Key']);

await client.checkStatus('TXNTEST');
const statusBody = JSON.parse(capture.last.body);
assert.equal(statusBody.action, 'check_status');

const raw = '{"event":"payment.success"}';
const secret = 'whsec_test';
const sig = (await import('node:crypto')).createHmac('sha256', secret).update(raw).digest('hex');
assert.equal(verifySignature(raw, sig, secret), true);
assert.equal(verifySignature(raw, 'bad', secret), false);

console.log('Node SDK shape tests OK');
