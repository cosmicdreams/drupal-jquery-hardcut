import { randomUUID } from 'node:crypto';
import { testBaseURL } from './factories/test-site';

export default function globalSetup(): void {
  testBaseURL();
  process.env.DRUPAL_PLAYWRIGHT_RUN_ID = randomUUID();
}
