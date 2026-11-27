import { expect, test as base } from '@playwright/test';
import type { BrowserContext } from '@playwright/test';
import { installSite } from '../factories/test-site';
import type { SiteOptions, TestSite } from '../factories/test-site';
import { DrupalPage } from '../pages/DrupalPage';
import { LoginPage } from '../pages/LoginPage';
import { UmamiTestPage } from '../pages/UmamiTestPage';

type Fixtures = {
  siteOptions: SiteOptions;
  site: TestSite;
  drupalPage: DrupalPage;
  umamiPage: UmamiTestPage;
  reportsUserPage: LoginPage;
  adminAuthState: Awaited<ReturnType<BrowserContext['storageState']>>;
  adminPage: DrupalPage;
};

const loginSetup = 'core/tests/Drupal/TestSite/PlaywrightLoginTestSetup.php';

export const test = base.extend<Fixtures>({
  siteOptions: [{}, { option: true }],
  site: async ({ siteOptions }, use) => {
    const site = await installSite(siteOptions);
    try {
      await use(site);
    } finally {
      await site.cleanup();
    }
  },
  baseURL: async ({ site }, use) => {
    await use(site.baseURL);
  },
  // The default Playwright page starts with only the test site routing cookie.
  // Playwright closes the context after each test.
  storageState: async ({ site }, use) => {
    await use({
      cookies: [
        {
          ...site.routingCookie,
          expires: -1,
          httpOnly: true,
          secure: false,
          sameSite: 'Lax',
        },
      ],
      origins: [],
    });
  },
  drupalPage: async ({ page }, use) => {
    await use(new DrupalPage(page));
  },
  umamiPage: async ({ page }, use) => {
    await use(new UmamiTestPage(page));
  },
  reportsUserPage: async ({ page }, use) => {
    const loginPage = new LoginPage(page);
    await test.step('Sign in with the reports account credentials', async () => {
      await loginPage.signIn('user', '123');
      await expect.poll(() => loginPage.hasSession()).toBe(true);
    });
    await use(loginPage);
  },
  adminAuthState: async ({ playwright, site, storageState }, use) => {
    const request = await playwright.request.newContext({ storageState });
    try {
      const response = await request.get(await site.adminLoginURL());
      const state = await request.storageState();
      if (
        !response.ok() ||
        !state.cookies.some(({ name }) => /^S?SESS/.test(name))
      ) {
        throw new Error(
          `Administrator login failed (HTTP ${response.status()}).`,
        );
      }
      await use(state);
    } finally {
      await request.dispose();
    }
  },
  adminPage: async ({ browser, baseURL, adminAuthState }, use) => {
    const context = await browser.newContext({
      baseURL,
      storageState: adminAuthState,
    });
    try {
      await use(new DrupalPage(await context.newPage()));
    } finally {
      await context.close();
    }
  },
});

export { expect, loginSetup };
