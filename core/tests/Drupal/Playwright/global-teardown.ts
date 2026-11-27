import { sweepOrphanedSites } from './factories/test-site';

export default async function globalTeardown(): Promise<void> {
  await sweepOrphanedSites();
}
