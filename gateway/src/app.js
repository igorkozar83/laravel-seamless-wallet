import Fastify from 'fastify';
import { fromCents, toCents } from './money.js';
import { signRequest } from './sign.js';

const RETRYABLE_STATUS = new Set([429, 500, 502, 503, 504]);

const callbackSchema = {
  params: {
    type: 'object',
    required: ['action'],
    properties: { action: { type: 'string', enum: ['bet', 'win'] } },
  },
  body: {
    type: 'object',
    required: ['txn', 'player', 'round', 'amount'],
    additionalProperties: false,
    properties: {
      txn: { type: 'string', minLength: 1, maxLength: 100 },
      player: { type: 'integer', minimum: 1 },
      round: { type: 'string', minLength: 1, maxLength: 100 },
      amount: { type: 'string', pattern: '^\\d{1,9}(\\.\\d{1,2})?$' },
      currency: { type: 'string', minLength: 3, maxLength: 3 },
    },
  },
};

/**
 * The provider gateway sits between game studios and the wallet API:
 *
 * - translates the studio's payload ("12.50" as a string) into the wallet's (1250 cents)
 * - signs every forwarded request, so the wallet API only trusts traffic that passed through here
 * - retries when the wallet is briefly unavailable. That is safe only because the wallet is
 *   idempotent on the provider's transaction id: sending the same bet twice charges it once.
 */
export function buildGateway({
  walletApiUrl,
  secret,
  fetchImpl = fetch,
  now = () => Math.floor(Date.now() / 1000),
  sleep = (ms) => new Promise((resolve) => setTimeout(resolve, ms)),
  attempts = 3,
  baseDelayMs = 100,
  timeoutMs = 5000,
  logger = false,
} = {}) {
  const app = Fastify({ logger });

  app.get('/health', async () => ({ status: 'ok' }));

  app.post('/callbacks/:action', { schema: callbackSchema }, async (request, reply) => {
    const { action } = request.params;
    const { txn, player, round, amount, currency = 'EUR' } = request.body;

    if (currency !== 'EUR') {
      return reply.code(400).send({ status: 'UNSUPPORTED_CURRENCY', txn });
    }

    let amountCents;
    try {
      amountCents = toCents(amount);
    } catch {
      return reply.code(400).send({ status: 'INVALID_AMOUNT', txn });
    }

    const body = JSON.stringify({
      player_id: player,
      amount_cents: amountCents,
      transaction_id: txn,
      round_id: round,
    });

    let response;
    for (let attempt = 1; attempt <= attempts; attempt += 1) {
      response = await send(`${walletApiUrl}/api/provider/${action}`, body);

      if (response && !RETRYABLE_STATUS.has(response.status)) {
        break;
      }

      if (attempt < attempts) {
        request.log.warn({ txn, attempt, status: response?.status }, 'wallet unavailable, retrying');
        await sleep(baseDelayMs * 2 ** (attempt - 1));
      }
    }

    if (!response || RETRYABLE_STATUS.has(response.status)) {
      // the studio will retry the callback later, and the wallet will deduplicate it
      return reply.code(502).send({ status: 'WALLET_UNAVAILABLE', txn });
    }

    const payload = await response.json().catch(() => ({}));

    if (response.status === 200 || response.status === 201) {
      return {
        status: 'OK',
        txn,
        balance: fromCents(payload.data.balance_after_cents),
        duplicate: response.status === 200,
      };
    }

    if (response.status === 422 && payload.error === 'INSUFFICIENT_FUNDS') {
      return { status: 'INSUFFICIENT_FUNDS', txn, balance: fromCents(payload.balance_cents) };
    }

    if (response.status === 409) {
      // the studio reused an id for a different movement: retrying can never fix that
      return reply.code(409).send({ status: 'TRANSACTION_ID_CONFLICT', txn });
    }

    if (response.status === 422) {
      return reply.code(400).send({ status: 'REJECTED', txn, errors: payload.errors ?? {} });
    }

    request.log.error({ txn, status: response.status }, 'unexpected wallet response');
    return reply.code(502).send({ status: 'WALLET_ERROR', txn });
  });

  // one attempt: freshly signed (the timestamp must be current), bounded by a timeout
  async function send(url, body) {
    try {
      return await fetchImpl(url, {
        method: 'POST',
        headers: {
          'Content-Type': 'application/json',
          Accept: 'application/json',
          ...signRequest(body, secret, now()),
        },
        body,
        signal: AbortSignal.timeout(timeoutMs),
      });
    } catch {
      return null; // network error or timeout: treated as retryable
    }
  }

  return app;
}
