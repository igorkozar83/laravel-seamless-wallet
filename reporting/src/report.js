// Pure translation from a stream entry to the change it makes to a daily report.
// Kept free of Redis so the business rules can be tested on their own.

export function parseEntry(fields) {
  // ioredis returns stream fields as a flat array: [k1, v1, k2, v2, ...]
  const entry = {};
  for (let i = 0; i < fields.length; i += 2) {
    entry[fields[i]] = fields[i + 1];
  }

  return {
    outboxId: entry.outbox_id,
    topic: entry.topic,
    payload: JSON.parse(entry.payload),
  };
}

/**
 * GGR (gross gaming revenue) = money players bet minus money paid back as wins.
 * Bets arrive as negative ledger amounts (money leaving the player's wallet), so they are
 * flipped to positive "bets" here. Deposits and other movements don't affect GGR.
 */
export function toDelta({ topic, payload }) {
  const date = payload.occurred_at.slice(0, 10); // reports are per UTC day

  if (topic === 'wallet.bet') {
    return { date, betsCents: -payload.amount_cents, winsCents: 0 };
  }

  if (topic === 'wallet.win') {
    return { date, betsCents: 0, winsCents: payload.amount_cents };
  }

  return null;
}

export function toReport(date, hash) {
  const betsCents = Number(hash.bets_cents ?? 0);
  const winsCents = Number(hash.wins_cents ?? 0);

  return {
    date,
    bets_cents: betsCents,
    wins_cents: winsCents,
    ggr_cents: betsCents - winsCents,
    transactions: Number(hash.transactions ?? 0),
  };
}
