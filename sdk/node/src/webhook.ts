import { createHmac, timingSafeEqual } from 'node:crypto';

/** Verify X-UniWeb-Signature on inbound webhook deliveries. */
export function verifySignature(rawBody: string, signatureHeader: string, signingSecret: string): boolean {
  if (!rawBody || !signatureHeader || !signingSecret) {
    return false;
  }
  const expected = createHmac('sha256', signingSecret).update(rawBody).digest('hex');
  try {
    return timingSafeEqual(Buffer.from(expected), Buffer.from(signatureHeader));
  } catch {
    return false;
  }
}

export function parseEvent<T = Record<string, unknown>>(
  rawBody: string,
  signatureHeader: string,
  signingSecret: string,
): T | null {
  if (!verifySignature(rawBody, signatureHeader, signingSecret)) {
    return null;
  }
  return JSON.parse(rawBody) as T;
}
