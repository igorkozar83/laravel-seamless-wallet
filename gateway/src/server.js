import { buildGateway } from './app.js';

const app = buildGateway({
  walletApiUrl: process.env.WALLET_API_URL ?? 'http://localhost:8000',
  secret: process.env.PROVIDER_WEBHOOK_SECRET ?? 'local-dev-secret',
  logger: true,
});

const port = Number(process.env.PORT ?? 3000);

app.listen({ host: '0.0.0.0', port }).catch((error) => {
  app.log.error(error);
  process.exit(1);
});

for (const signal of ['SIGINT', 'SIGTERM']) {
  process.on(signal, () => app.close().then(() => process.exit(0)));
}
