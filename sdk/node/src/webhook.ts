import { createHash, createHmac, timingSafeEqual } from 'node:crypto';

/**
 * Timing-safe hex compare — unequal lengths still avoid early-exit string compare in app code.
 */
export function timingSafeEqualHex(a: string, b: string): boolean {
  const bufA = Buffer.from(String(a || ''), 'utf8');
  const bufB = Buffer.from(String(b || ''), 'utf8');
  if (bufA.length !== bufB.length) {
    return (
      timingSafeEqual(
        createHash('sha256').update(bufA).digest(),
        createHash('sha256').update(bufB).digest(),
      ) && false
    );
  }
  return timingSafeEqual(bufA, bufB);
}

/** Strip optional sha256= prefix from partner headers. */
export function stripSha256Prefix(value: string): string {
  const v = String(value || '').trim();
  const m = /^sha256=(.+)$/i.exec(v);
  return m ? m[1].trim() : v;
}

/** HMAC-SHA256(rawBody, secret) → hex — timing-safe compare. */
export function verifyHmacSha256Hex(rawBody: string, secret: string, receivedHex: string): boolean {
  if (!rawBody || !secret) {
    return false;
  }
  const received = stripSha256Prefix(receivedHex);
  if (!received) {
    return false;
  }
  const expected = createHmac('sha256', secret).update(rawBody, 'utf8').digest('hex');
  return timingSafeEqualHex(expected, received);
}

/**
 * Verify X-UniWeb-Signature on inbound webhook deliveries.
 * Pass optional previousSecret during merchant-side rotation grace.
 */
export function verifySignature(
  rawBody: string,
  signatureHeader: string,
  signingSecret: string,
  previousSecret?: string,
): boolean {
  if (!rawBody || !signatureHeader || !signingSecret) {
    return false;
  }
  if (verifyHmacSha256Hex(rawBody, signingSecret, signatureHeader)) {
    return true;
  }
  if (previousSecret && previousSecret !== signingSecret) {
    return verifyHmacSha256Hex(rawBody, previousSecret, signatureHeader);
  }
  return false;
}

export function parseEvent<T = Record<string, unknown>>(
  rawBody: string,
  signatureHeader: string,
  signingSecret: string,
  previousSecret?: string,
): T | null {
  if (!verifySignature(rawBody, signatureHeader, signingSecret, previousSecret)) {
    return null;
  }
  return JSON.parse(rawBody) as T;
}
