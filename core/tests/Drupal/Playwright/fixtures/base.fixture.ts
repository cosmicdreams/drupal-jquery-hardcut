import { expect, test as base } from '@playwright/test';
import type { APIRequest, BrowserContext, Page } from '@playwright/test';
import { installSite, siteFixtureTimeout } from '../factories/test-site';
import type { SiteOptions, TestSite } from '../factories/test-site';
import { AjaxPromiseFormPage } from '../pages/AjaxPromiseFormPage';
import { AutocompleteTestPage } from '../pages/AutocompleteTestPage';
import { DeprecationTestPage } from '../pages/DeprecationTestPage';
import { DisplaceTestPage } from '../pages/DisplaceTestPage';
import { DrupalPage } from '../pages/DrupalPage';
import { HtmxAttachmentsPage } from '../pages/HtmxAttachmentsPage';
import { LoginPage } from '../pages/LoginPage';
import { MachineNameTestPage } from '../pages/MachineNameTestPage';
import { MessageTestPage } from '../pages/MessageTestPage';
import { NavigationPage } from '../pages/NavigationPage';
import { OnceTestPage } from '../pages/OnceTestPage';
import { PrimaryTabsTestPage } from '../pages/PrimaryTabsTestPage';
import { TabbingManagerTestPage } from '../pages/TabbingManagerTestPage';
import { UmamiTestPage } from '../pages/UmamiTestPage';

type AuthState = Awaited<ReturnType<BrowserContext['storageState']>>;

type Fixtures = {
  siteOptions: SiteOptions;
  site: TestSite;
  siteThemes: Partial<Record<'admin' | 'default', string>>;
  themeSetup: void;
  a11yAdminPage: DrupalPage;
  a11yDefaultPage: DrupalPage;
  messageTestPage: MessageTestPage;
  primaryTabsTestPage: PrimaryTabsTestPage;
  siteCleanup: (site: TestSite) => void;
  drupalPage: DrupalPage;
  umamiPage: UmamiTestPage;
  ajaxPromiseFormPage: AjaxPromiseFormPage;
  autocompleteTestPage: AutocompleteTestPage;
  deprecationTestPage: DeprecationTestPage;
  displaceTestPage: DisplaceTestPage;
  htmxAttachmentsPage: HtmxAttachmentsPage;
  machineNameTestPage: MachineNameTestPage;
  navigationPage: NavigationPage;
  onceTestPage: OnceTestPage;
  tabbingManagerTestPage: TabbingManagerTestPage;
  /**
   * Signs in through the browser for the Reports deprecation check, which
   * reads session storage from the tab that performed the sign-in.
   */
  reportsLoginPage: LoginPage;
  reportsUserPage: DrupalPage;
  adminAuthState: AuthState;
  adminBrowserPage: Page;
  adminPage: DrupalPage;
};

const autocompleteSetup =
  'core/tests/Drupal/TestSite/PlaywrightAutocompleteTestSetup.php';
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
  siteThemes: [{}, { option: true }],
  themeSetup: async ({ siteThemes, site, playwright, storageState }, use) => {
    const themes = Object.entries(siteThemes);
    if (themes.length) {
      const state = await captureAuthState(
        playwright.request,
        site,
        storageState,
        1,
      );
      const request = await playwright.request.newContext({
        baseURL: site.baseURL,
        storageState: state,
      });
      try {
        for (const [role, theme] of themes) {
          if (!/^[a-z][a-z0-9_]*$/.test(theme)) {
            throw new Error(`Invalid theme machine name: ${theme}`);
          }
          const response = await request.post(
            `admin/theme/install_${role}/${theme}`,
          );
          const installed = response.ok() ? await response.json() : null;
          if (installed?.theme !== theme || installed?.role !== role) {
            throw new Error(
              `Could not install ${theme} as the ${role} theme (HTTP ${response.status()}).`,
            );
          }
        }
      } finally {
        await request.dispose();
      }
    }
    await use();
  },
  a11yAdminPage: async ({ themeSetup, adminPage }, use) => {
    void themeSetup;
    await use(adminPage);
  },
  a11yDefaultPage: async ({ themeSetup, drupalPage }, use) => {
    void themeSetup;
    await use(drupalPage);
  },
  messageTestPage: async ({ themeSetup, page }, use) => {
    void themeSetup;
    await use(new MessageTestPage(page));
  },
  primaryTabsTestPage: async (
    { browser, baseURL, playwright, site, storageState },
    use,
  ) => {
    // The primary tabs setup creates the node administrator immediately after UID 1.
    const state = await captureAuthState(
      playwright.request,
      site,
      storageState,
      2,
    );
    const context = await browser.newContext({ baseURL, storageState: state });
    try {
      await use(new PrimaryTabsTestPage(await context.newPage()));
    } finally {
      await context.close();
    }
  },
  // Give cleanup its own timeout so a slow install cannot use its lock budget.
  siteCleanup: [
    // Playwright requires an object pattern even for fixtures without dependencies.
    // eslint-disable-next-line no-empty-pattern
    async ({}, use) => {
      let site: TestSite | undefined;
      try {
        await use((installedSite) => {
          site = installedSite;
        });
      } finally {
        await site?.cleanup();
      }
    },
    { timeout: siteFixtureTimeout },
  ],
  site: [
    async ({ siteOptions, siteThemes, siteCleanup }, use) => {
      const site = await installSite({
        ...siteOptions,
        modules: Object.keys(siteThemes).length
          ? [...(siteOptions.modules ?? []), 'playwright_theme_install_utility']
          : siteOptions.modules,
      });
      siteCleanup(site);
      await use(site);
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
  autocompleteTestPage: async (
    { browser, baseURL, playwright, site, storageState },
    use,
  ) => {
    // The setup creates the autocomplete account immediately after UID 1.
    const state = await captureAuthState(
      playwright.request,
      site,
      storageState,
      2,
    );
    const context = await browser.newContext({ baseURL, storageState: state });
    try {
      await use(new AutocompleteTestPage(await context.newPage()));
    } finally {
      await context.close();
    }
  },
  deprecationTestPage: async ({ page }, use) => {
    await use(new DeprecationTestPage(page));
  },
  displaceTestPage: async ({ page }, use) => {
    await use(new DisplaceTestPage(page));
  },
  htmxAttachmentsPage: async ({ page }, use) => {
    await use(new HtmxAttachmentsPage(page));
  },
  machineNameTestPage: async ({ page }, use) => {
    await use(new MachineNameTestPage(page));
  },
  navigationPage: async ({ adminBrowserPage }, use) => {
    await use(new NavigationPage(adminBrowserPage));
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
  adminPage: async ({ adminBrowserPage }, use) => {
    await use(new DrupalPage(adminBrowserPage));
  },
  adminBrowserPage: async ({ browser, baseURL, adminAuthState }, use) => {
    const context = await browser.newContext({
      baseURL,
      storageState: adminAuthState,
    });
    try {
      await use(await context.newPage());
    } finally {
      await context.close();
    }
  },
});

export { autocompleteSetup, expect, loginSetup };
