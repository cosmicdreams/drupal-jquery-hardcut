import { expect, test } from '../fixtures/base.fixture';

test(
  'PW-harness-admin-session',
  { tag: ['@core', '@local'] },
  async ({ adminPage }) => {
    await test.step('Verify the administrator session on the fixture site', async () => {
      await adminPage.open('user');
      await expect.poll(() => adminPage.hasSession()).toBe(true);
    });
  },
);
