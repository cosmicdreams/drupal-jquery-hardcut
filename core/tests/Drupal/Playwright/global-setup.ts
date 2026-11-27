import { randomUUID } from 'node:crypto';
import { recoverStaleSites, testBaseURL } from './factories/test-site';

export default async function globalSetup(): Promise<void> {
  testBaseURL();
  await recoverStaleSites();
  process.env.DRUPAL_PLAYWRIGHT_RUN_ID = randomUUID();
}
