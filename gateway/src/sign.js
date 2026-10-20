import { createHmac } from 'node:crypto';

// Same scheme the wallet API verifies: HMAC-SHA256 of "timestamp.body" with the shared secret.
// The body must be signed exactly as it is sent, so callers pass the serialized string.
export function signRequest(body, secret, timestamp) {
  const signature = createHmac('sha256', secret).update(`${timestamp}.${body}`).digest('hex');

  return { 'X-Timestamp': String(timestamp), 'X-Signature': signature };
}
