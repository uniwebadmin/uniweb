import { createHmac, timingSafeEqual } from 'node:crypto';
export function verifySignature(rawBody, signatureHeader, signingSecret) {
    if (!rawBody || !signatureHeader || !signingSecret) {
        return false;
    }
    const expected = createHmac('sha256', signingSecret).update(rawBody).digest('hex');
    try {
        return timingSafeEqual(Buffer.from(expected), Buffer.from(signatureHeader));
    }
    catch {
        return false;
    }
}
export function parseEvent(rawBody, signatureHeader, signingSecret) {
    if (!verifySignature(rawBody, signatureHeader, signingSecret)) {
        return null;
    }
    return JSON.parse(rawBody);
}
