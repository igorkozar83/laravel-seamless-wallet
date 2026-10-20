import { test } from 'node:test';
import assert from 'node:assert/strict';
import { signRequest } from '../src/sign.js';

test('signatures match what the PHP wallet API computes with hash_hmac', () => {
  const body = JSON.stringify({ player_id: 7, amount_cents: 1250, transaction_id: 'PRG-777', round_id: 'R-1' });

  // reference value produced by: hash_hmac('sha256', "1700000000.$body", 'local-dev-secret')
  assert.deepEqual(signRequest(body, 'local-dev-secret', 1700000000), {
    'X-Timestamp': '1700000000',
    'X-Signature': '0d2312650543d95e1281e3f00bcc1eff9aa050742f21c55cb7d685e7fd93e559',
  });
});

test('any change to the body changes the signature', () => {
  const original = signRequest('{"amount_cents":1250}', 'secret', 1700000000)['X-Signature'];
  const tampered = signRequest('{"amount_cents":9250}', 'secret', 1700000000)['X-Signature'];

  assert.notEqual(original, tampered);
});
