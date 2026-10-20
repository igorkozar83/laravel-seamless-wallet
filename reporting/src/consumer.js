import { parseEntry, toDelta } from './report.js';

// Deduplicate and aggregate in ONE atomic step. The outbox relay delivers at least once, so the
// same event can arrive twice; if "have I seen it?" and "add it" were separate calls, a crash
// between them would either lose the event or count it twice.
const APPLY_EVENT = `
  if redis.call('SADD', KEYS[1], ARGV[1]) == 0 then
    return 0
  end
  redis.call('HINCRBY', KEYS[2], 'bets_cents', ARGV[2])
  redis.call('HINCRBY', KEYS[2], 'wins_cents', ARGV[3])
  redis.call('HINCRBY', KEYS[2], 'transactions', 1)
  return 1
`;

export const reportKey = (date) => `reporting:daily:${date}`;
// No TTL on either key: the stream is never trimmed, so an old entry can always be replayed, and the
// set that says "already counted" has to live exactly as long as the report it protects. One small
// set per day; archive both together if they ever need to go.
const processedKey = (date) => `reporting:processed:${date}`;

/**
 * A Redis Streams consumer-group reader.
 *
 * - consumer groups let several reporting instances share the work without double-processing
 * - entries are acknowledged (XACK) only after they are applied, so a crash leaves them pending
 * - on start, the consumer takes over entries a dead instance left pending, re-reads its own pending
 *   entries, then switches to new ones
 */
export function createConsumer({ redis, stream, group, consumer, blockMs = 5000, claimIdleMs = 60_000, log = console }) {
  redis.defineCommand('applyWalletEvent', { numberOfKeys: 2, lua: APPLY_EVENT });

  let running = false;

  async function ensureGroup() {
    try {
      await redis.xgroup('CREATE', stream, group, '0', 'MKSTREAM');
    } catch (error) {
      if (!String(error.message).includes('BUSYGROUP')) throw error;
    }
  }

  async function apply(id, fields) {
    const event = parseEntry(fields);
    const delta = toDelta(event);

    if (delta) {
      await redis.applyWalletEvent(
        processedKey(delta.date),
        reportKey(delta.date),
        event.outboxId,
        delta.betsCents,
        delta.winsCents,
      );
    }

    await redis.xack(stream, group, id);
  }

  // '>' = never delivered entries; any other id = my pending (delivered, never acknowledged)
  // entries after that id. Returns the ids it read, whether or not they applied.
  async function readBatch(fromId, block) {
    const args = ['GROUP', group, consumer, 'COUNT', 100];
    if (block) args.push('BLOCK', blockMs);
    const result = await redis.xreadgroup(...args, 'STREAMS', stream, fromId);
    const entries = result?.[0]?.[1] ?? [];

    for (const [id, fields] of entries) {
      try {
        await apply(id, fields);
      } catch (error) {
        // left unacknowledged on purpose: it stays pending and is retried on the next start
        log.error?.({ id, error: error.message }, 'failed to apply wallet event');
      }
    }

    return entries.map(([id]) => id);
  }

  // Pending entries belong to a consumer NAME. If an instance dies and its replacement starts under
  // another name, nobody would ever read the dead one's entries, so take over anything idle too long.
  async function reclaimStale() {
    let next = '0-0';
    do {
      [next] = await redis.xautoclaim(stream, group, consumer, claimIdleMs, next, 'COUNT', 100);
    } while (next !== '0-0');
  }

  // Walks the pending list with a cursor instead of re-reading from '0': an entry that keeps
  // failing stays pending for the next start, but can't trap recovery in an endless loop.
  async function drainPending() {
    await reclaimStale();
    let cursor = '0';
    for (let ids = await readBatch(cursor, false); ids.length > 0; ids = await readBatch(cursor, false)) {
      cursor = ids.at(-1);
    }
  }

  return {
    ensureGroup,
    drainPending,
    readNew: async () => (await readBatch('>', true)).length,
    async start() {
      running = true;
      await ensureGroup();
      await drainPending();
      let recoveredAt = Date.now();
      while (running) {
        await readBatch('>', true);
        // entries that failed while running (or that another dead instance left) get retried too,
        // not only on the next restart
        if (Date.now() - recoveredAt >= claimIdleMs) {
          await drainPending();
          recoveredAt = Date.now();
        }
      }
    },
    stop() {
      running = false;
    },
  };
}
