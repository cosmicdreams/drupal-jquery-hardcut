import { expect, loginSetup, test } from '../fixtures/base.fixture';

test.use({ siteOptions: { setupFile: loginSetup } });

test(
  'PW-login-reports-session-isolation',
  { tag: ['@core', '@local'] },
  async ({ drupalPage, reportsUserPage }) => {
    await test.step('Check that only the reports page has a session cookie', async () => {
      await drupalPage.open('user/login');
      await reportsUserPage.open('admin/reports');
      expect({
        defaultPage: await drupalPage.hasSession(),
        reportsPage: await reportsUserPage.hasSession(),
      }).toEqual({ defaultPage: false, reportsPage: true });
    });
  },
);
