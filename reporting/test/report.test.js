import { test } from 'node:test';
import assert from 'node:assert/strict';
import { parseEntry, toDelta, toReport } from '../src/report.js';

const bet = { topic: 'wallet.bet', payload: { amount_cents: -1000, occurred_at: '2026-10-20T13:45:00+00:00' } };
const win = { topic: 'wallet.win', payload: { amount_cents: 2500, occurred_at: '2026-10-20T13:46:00+00:00' } };

test('stream fields are parsed into an event', () => {
  const event = parseEntry(['outbox_id', '42', 'topic', 'wallet.bet', 'payload', JSON.stringify(bet.payload)]);

  assert.deepEqual(event, { outboxId: '42', topic: 'wallet.bet', payload: bet.payload });
});

test('a bet counts as positive turnover on its UTC day', () => {
  assert.deepEqual(toDelta(bet), { date: '2026-10-20', betsCents: 1000, winsCents: 0 });
});

test('a win counts as money paid out', () => {
  assert.deepEqual(toDelta(win), { date: '2026-10-20', betsCents: 0, winsCents: 2500 });
});

test('deposits do not affect gaming revenue', () => {
  assert.equal(toDelta({ topic: 'wallet.deposit', payload: { amount_cents: 5000, occurred_at: '2026-10-20T00:00:00Z' } }), null);
});

test('GGR is bets minus wins, and an empty day is all zeros', () => {
  assert.deepEqual(toReport('2026-10-20', { bets_cents: '12000', wins_cents: '9500', transactions: '7' }), {
    date: '2026-10-20',
    bets_cents: 12000,
    wins_cents: 9500,
    ggr_cents: 2500,
    transactions: 7,
  });

  assert.deepEqual(toReport('2026-10-21', {}), {
    date: '2026-10-21',
    bets_cents: 0,
    wins_cents: 0,
    ggr_cents: 0,
    transactions: 0,
  });
});
