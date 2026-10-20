import { hostname } from 'node:os';
import Redis from 'ioredis';
import { buildApi } from './api.js';
import { createConsumer } from './consumer.js';

const redisUrl = process.env.REDIS_URL ?? 'redis://localhost:6379';

// a blocking XREADGROUP holds its connection, so the consumer and the HTTP API use separate ones
const consumerRedis = new Redis(redisUrl);
const apiRedis = new Redis(redisUrl);

const consumer = createConsumer({
  redis: consumerRedis,
  stream: process.env.WALLET_EVENTS_STREAM ?? 'wallet-events',
  group: 'reporting',
  consumer: process.env.CONSUMER_NAME ?? hostname(),
});

const api = buildApi({ redis: apiRedis, logger: true });

await api.listen({ host: '0.0.0.0', port: Number(process.env.PORT ?? 3001) });

consumer.start().catch((error) => {
  api.log.error(error, 'consumer stopped');
  process.exit(1);
});

for (const signal of ['SIGINT', 'SIGTERM']) {
  process.on(signal, async () => {
    consumer.stop();
    await api.close();
    consumerRedis.disconnect();
    apiRedis.disconnect();
    process.exit(0);
  });
}
