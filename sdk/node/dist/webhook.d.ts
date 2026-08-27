export declare function verifySignature(rawBody: string, signatureHeader: string, signingSecret: string): boolean;
export declare function parseEvent<T = Record<string, unknown>>(rawBody: string, signatureHeader: string, signingSecret: string): T | null;
