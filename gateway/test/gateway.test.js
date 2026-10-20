import { test } from 'node:test';
import assert from 'node:assert/strict';
import { buildGateway } from '../src/app.js';
import { signRequest } from '../src/sign.js';

const SECRET = 'test-secret';

// A fake wallet API: records every call and answers from a scripted list of responses.
function fakeWallet(responses) {
  const calls = [];
  const fetchImpl = async (url, init) => {
    calls.push({ url, init });
    const next = responses[Math.min(calls.length, responses.length) - 1];
    if (next instanceof Error) throw next;
    return new Response(JSON.stringify(next.body ?? {}), { status: next.status });
  };
  return { calls, fetchImpl };
}

function gatewayWith(wallet) {
  return buildGateway({
    walletApiUrl: 'http://wallet.test',
    secret: SECRET,
    fetchImpl: wallet.fetchImpl,
    now: () => 1700000000,
    sleep: async () => {},
  });
}

const callback = { txn: 'PRG-777', player: 7, round: 'R-1', amount: '12.50' };
const created = { status: 201, body: { data: { id: 1, balance_after_cents: 3750 } } };

test('a bet is translated, signed and forwarded to the wallet', async () => {
  const wallet = fakeWallet([created]);
  const app = gatewayWith(wallet);

  const response = await app.inject({ method: 'POST', url: '/callbacks/bet', payload: callback });

  assert.equal(response.statusCode, 200);
  assert.deepEqual(response.json(), { status: 'OK', txn: 'PRG-777', balance: '37.50', duplicate: false });

  const [{ url, init }] = wallet.calls;
  assert.equal(url, 'http://wallet.test/api/provider/bet');
  assert.deepEqual(JSON.parse(init.body), {
    player_id: 7,
    amount_cents: 1250,
    transaction_id: 'PRG-777',
    round_id: 'R-1',
  });
  assert.equal(init.headers['X-Signature'], signRequest(init.body, SECRET, 1700000000)['X-Signature']);
});

test('a retried callback the wallet already processed is reported as a duplicate', async () => {
  const wallet = fakeWallet([{ status: 200, body: { data: { id: 1, balance_after_cents: 3750 } } }]);

  const response = await gatewayWith(wallet).inject({ method: 'POST', url: '/callbacks/bet', payload: callback });

  assert.equal(response.json().duplicate, true);
});

test('the gateway retries while the wallet is unavailable, then succeeds', async () => {
  const wallet = fakeWallet([new TypeError('connection refused'), { status: 503 }, created]);

  const response = await gatewayWith(wallet).inject({ method: 'POST', url: '/callbacks/win', payload: callback });

  assert.equal(response.statusCode, 200);
  assert.equal(wallet.calls.length, 3);
  // every attempt carries the same transaction id, which is what makes retrying safe
  assert.ok(wallet.calls.every((call) => JSON.parse(call.init.body).transaction_id === 'PRG-777'));
});

test('after the last failed attempt the studio gets a 502 and can retry later', async () => {
  const wallet = fakeWallet([{ status: 503 }]);

  const response = await gatewayWith(wallet).inject({ method: 'POST', url: '/callbacks/bet', payload: callback });

  assert.equal(response.statusCode, 502);
  assert.equal(response.json().status, 'WALLET_UNAVAILABLE');
  assert.equal(wallet.calls.length, 3);
});

test('insufficient funds is mapped to the studio format and is not retried', async () => {
  const wallet = fakeWallet([{ status: 422, body: { error: 'INSUFFICIENT_FUNDS', balance_cents: 500 } }]);

  const response = await gatewayWith(wallet).inject({ method: 'POST', url: '/callbacks/bet', payload: callback });

  assert.deepEqual(response.json(), { status: 'INSUFFICIENT_FUNDS', txn: 'PRG-777', balance: '5.00' });
  assert.equal(wallet.calls.length, 1);
});

test('malformed callbacks are rejected before reaching the wallet', async () => {
  const wallet = fakeWallet([created]);
  const app = gatewayWith(wallet);

  const badAmount = await app.inject({ method: 'POST', url: '/callbacks/bet', payload: { ...callback, amount: '12.505' } });
  const badAction = await app.inject({ method: 'POST', url: '/callbacks/refund', payload: callback });
  const badCurrency = await app.inject({ method: 'POST', url: '/callbacks/bet', payload: { ...callback, currency: 'USD' } });
  const zeroAmount = await app.inject({ method: 'POST', url: '/callbacks/bet', payload: { ...callback, amount: '0.00' } });

  assert.equal(badAmount.statusCode, 400);
  assert.equal(badAction.statusCode, 400);
  assert.equal(badCurrency.statusCode, 400);
  assert.equal(zeroAmount.statusCode, 400);
  assert.equal(zeroAmount.json().status, 'INVALID_AMOUNT');
  assert.equal(wallet.calls.length, 0);
});

test('an id reused for a different movement is reported as a conflict, not retried', async () => {
  const wallet = fakeWallet([{ status: 409, body: { error: 'TRANSACTION_ID_CONFLICT' } }]);

  const response = await gatewayWith(wallet).inject({ method: 'POST', url: '/callbacks/bet', payload: callback });

  assert.equal(response.statusCode, 409);
  assert.deepEqual(response.json(), { status: 'TRANSACTION_ID_CONFLICT', txn: 'PRG-777' });
  assert.equal(wallet.calls.length, 1);
});
