import Fastify from 'fastify';
import { reportKey } from './consumer.js';
import { toReport } from './report.js';

export function buildApi({ redis, logger = false }) {
  const app = Fastify({ logger });

  app.get('/health', async () => ({ status: 'ok' }));

  app.get(
    '/reports/daily/:date',
    {
      schema: {
        params: {
          type: 'object',
          properties: { date: { type: 'string', pattern: '^\\d{4}-\\d{2}-\\d{2}$' } },
        },
      },
    },
    async (request) => toReport(request.params.date, await redis.hgetall(reportKey(request.params.date))),
  );

  return app;
}
