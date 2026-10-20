import { after, before, test } from 'node:test';
import assert from 'node:assert/strict';
import Redis from 'ioredis';
import { buildApi } from '../src/api.js';
import { createConsumer } from '../src/consumer.js';

// Runs against a real Redis when REDIS_URL is set (docker compose and CI do this); skipped otherwise.
const REDIS_URL = process.env.REDIS_URL;
const skip = REDIS_URL ? false : 'set REDIS_URL to run the Redis integration tests';

const DATE = '2099-01-01';
const STREAM = `wallet-events-test-${process.pid}`;
let redis;

function entry(outboxId, topic, amountCents) {
  return [
    'outbox_id', String(outboxId),
    'topic', topic,
    'payload', JSON.stringify({ amount_cents: amountCents, occurred_at: `${DATE}T12:00:00+00:00` }),
  ];
}

async function cleanup() {
  await redis.del(STREAM, `reporting:daily:${DATE}`, `reporting:processed:${DATE}`);
}

before(async () => {
  if (skip) return;
  redis = new Redis(REDIS_URL);
  await cleanup();
});

after(async () => {
  if (skip) return;
  await cleanup();
  redis.disconnect();
});

test('events are aggregated once, even when the relay delivers one twice', { skip }, async () => {
  await cleanup();
  const consumer = createConsumer({ redis, stream: STREAM, group: 'reporting', consumer: 'test-1', blockMs: 100 });
  await consumer.ensureGroup();

  await redis.xadd(STREAM, '*', ...entry(1, 'wallet.bet', -1000));
  await redis.xadd(STREAM, '*', ...entry(2, 'wallet.win', 2500));
  await redis.xadd(STREAM, '*', ...entry(1, 'wallet.bet', -1000)); // at-least-once redelivery
  await redis.xadd(STREAM, '*', ...entry(3, 'wallet.deposit', 9000));

  await consumer.readNew();

  const report = await buildApi({ redis }).inject({ method: 'GET', url: `/reports/daily/${DATE}` });
  assert.deepEqual(report.json(), {
    date: DATE,
    bets_cents: 1000,
    wins_cents: 2500,
    ggr_cents: -1500,
    transactions: 2,
  });

  const [pending] = await redis.xpending(STREAM, 'reporting');
  assert.equal(pending, 0, 'every entry is acknowledged after it is applied');
});

test('entries left pending by a crashed consumer are applied on restart', { skip }, async () => {
  await cleanup();
  const crashed = createConsumer({ redis, stream: STREAM, group: 'reporting', consumer: 'test-2', blockMs: 100 });
  await crashed.ensureGroup();
  await redis.xadd(STREAM, '*', ...entry(10, 'wallet.bet', -400));

  // read it like the consumer would, then "crash" before applying and acknowledging
  await redis.xreadgroup('GROUP', 'reporting', 'test-2', 'COUNT', 10, 'STREAMS', STREAM, '>');
  assert.equal((await redis.xpending(STREAM, 'reporting'))[0], 1);

  const restarted = createConsumer({ redis, stream: STREAM, group: 'reporting', consumer: 'test-2', blockMs: 100 });
  await restarted.drainPending();

  assert.equal((await redis.xpending(STREAM, 'reporting'))[0], 0);
  assert.equal(await redis.hget(`reporting:daily:${DATE}`, 'bets_cents'), '400');
});

test('entries left pending by a consumer that never comes back are taken over', { skip }, async () => {
  await cleanup();
  const dead = createConsumer({ redis, stream: STREAM, group: 'reporting', consumer: 'dead-1', blockMs: 100 });
  await dead.ensureGroup();
  await redis.xadd(STREAM, '*', ...entry(30, 'wallet.bet', -900));
  await redis.xreadgroup('GROUP', 'reporting', 'dead-1', 'COUNT', 10, 'STREAMS', STREAM, '>');

  // a replacement instance with a different name, and "idle too long" shortened for the test
  const replacement = createConsumer({
    redis, stream: STREAM, group: 'reporting', consumer: 'fresh-1', blockMs: 100, claimIdleMs: 0,
  });
  await replacement.drainPending();

  assert.equal((await redis.xpending(STREAM, 'reporting'))[0], 0);
  assert.equal(await redis.hget(`reporting:daily:${DATE}`, 'bets_cents'), '900');
});

test('a pending entry that keeps failing does not trap recovery', { skip }, async () => {
  await cleanup();
  const crashed = createConsumer({ redis, stream: STREAM, group: 'reporting', consumer: 'test-3', blockMs: 100 });
  await crashed.ensureGroup();
  await redis.xadd(STREAM, '*', 'outbox_id', '20', 'topic', 'wallet.bet', 'payload', 'not json');
  await redis.xadd(STREAM, '*', ...entry(21, 'wallet.bet', -700));
  await redis.xreadgroup('GROUP', 'reporting', 'test-3', 'COUNT', 10, 'STREAMS', STREAM, '>');

  const restarted = createConsumer({
    redis, stream: STREAM, group: 'reporting', consumer: 'test-3', blockMs: 100, log: { error() {} },
  });
  await restarted.drainPending(); // must return instead of re-reading the broken entry forever

  assert.equal((await redis.xpending(STREAM, 'reporting'))[0], 1, 'the broken entry stays pending');
  assert.equal(await redis.hget(`reporting:daily:${DATE}`, 'bets_cents'), '700');
});

test('the report endpoint rejects malformed dates', async () => {
  const app = buildApi({ redis: { hgetall: async () => ({}) } });

  const response = await app.inject({ method: 'GET', url: '/reports/daily/yesterday' });

  assert.equal(response.statusCode, 400);
});
