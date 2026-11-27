import { expect, test as base } from '@playwright/test';
import type { APIRequest, BrowserContext } from '@playwright/test';
import { installSite, siteFixtureTimeout } from '../factories/test-site';
import type { SiteOptions, TestSite } from '../factories/test-site';
import { AjaxPromiseFormPage } from '../pages/AjaxPromiseFormPage';
import { DeprecationTestPage } from '../pages/DeprecationTestPage';
import { DisplaceTestPage } from '../pages/DisplaceTestPage';
import { DrupalPage } from '../pages/DrupalPage';
import { LoginPage } from '../pages/LoginPage';
import { OnceTestPage } from '../pages/OnceTestPage';
import { TabbingManagerTestPage } from '../pages/TabbingManagerTestPage';
import { UmamiTestPage } from '../pages/UmamiTestPage';

type AuthState = Awaited<ReturnType<BrowserContext['storageState']>>;

type Fixtures = {
  siteOptions: SiteOptions;
  site: TestSite;
  drupalPage: DrupalPage;
  umamiPage: UmamiTestPage;
  ajaxPromiseFormPage: AjaxPromiseFormPage;
  deprecationTestPage: DeprecationTestPage;
  displaceTestPage: DisplaceTestPage;
  onceTestPage: OnceTestPage;
  tabbingManagerTestPage: TabbingManagerTestPage;
  /**
   * Signs in through the browser for the Reports deprecation check, which
   * reads session storage from the tab that performed the sign-in.
   */
  reportsLoginPage: LoginPage;
  reportsUserPage: DrupalPage;
  adminAuthState: AuthState;
  adminPage: DrupalPage;
};

const loginSetup = 'core/tests/Drupal/TestSite/PlaywrightLoginTestSetup.php';

async function captureAuthState(
  api: APIRequest,
  site: TestSite,
  storageState: string | AuthState | undefined,
  uid: number,
): Promise<AuthState> {
  const request = await api.newContext({ storageState });
  try {
    const response = await request.get(await site.loginURL(uid));
    const state = await request.storageState();
    if (
      !response.ok() ||
      !state.cookies.some(({ name }) => /^S?SESS/.test(name))
    ) {
      throw new Error(
        `Login for user ${uid} failed (HTTP ${response.status()}).`,
      );
    }
    return state;
  } finally {
    await request.dispose();
  }
}

export const test = base.extend<Fixtures>({
  siteOptions: [{}, { option: true }],
  site: [
    async ({ siteOptions }, use) => {
      const site = await installSite(siteOptions);
      try {
        await use(site);
      } finally {
        await site.cleanup();
      }
    },
    { timeout: siteFixtureTimeout },
  ],
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
  ajaxPromiseFormPage: async ({ page }, use) => {
    await use(new AjaxPromiseFormPage(page));
  },
  deprecationTestPage: async ({ page }, use) => {
    await use(new DeprecationTestPage(page));
  },
  displaceTestPage: async ({ page }, use) => {
    await use(new DisplaceTestPage(page));
  },
  onceTestPage: async ({ page }, use) => {
    await use(new OnceTestPage(page));
  },
  tabbingManagerTestPage: async ({ page }, use) => {
    await use(new TabbingManagerTestPage(page));
  },
  reportsLoginPage: async ({ browser, baseURL, storageState }, use) => {
    const context = await browser.newContext({ baseURL, storageState });
    try {
      const loginPage = new LoginPage(await context.newPage());
      await test.step('Sign in with the reports account credentials', async () => {
        await loginPage.signIn('user', '123');
        await expect.poll(() => loginPage.hasSession()).toBe(true);
      });
      await use(loginPage);
    } finally {
      await context.close();
    }
  },
  reportsUserPage: async (
    { browser, baseURL, playwright, site, storageState },
    use,
  ) => {
    // The login setup creates the reports account as the first user after UID 1.
    const state = await captureAuthState(
      playwright.request,
      site,
      storageState,
      2,
    );
    const context = await browser.newContext({ baseURL, storageState: state });
    try {
      await use(new DrupalPage(await context.newPage()));
    } finally {
      await context.close();
    }
  },
  adminAuthState: async ({ playwright, site, storageState }, use) => {
    await use(
      await captureAuthState(playwright.request, site, storageState, 1),
    );
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
