import { expect, test } from '../fixtures/base.fixture';
import type { DrupalPage } from '../pages/DrupalPage';

/**
 * Ports a11yTestAdmin.js.
 *
 * @see https://www.drupal.org/i/3223147
 * @see https://www.drupal.org/i/3293469
 * @see https://www.drupal.org/i/3318394
 * @see https://www.drupal.org/i/3501457
 * @see https://www.drupal.org/i/3582098
 */
const adminTheme = process.env.DRUPAL_TEST_ADMIN_THEME;
test.use({
  siteOptions: { profile: 'playwright_a11y_testing' },
  // If an admin theme other than Default Admin is requested, install it.
  siteThemes:
    adminTheme && adminTheme !== 'default_admin' ? { admin: adminTheme } : {},
});

const cases: readonly {
  name: string;
  path: string;
  viewport?: { width: number; height: number };
  options?: Parameters<DrupalPage['scanAccessibility']>[1];
}[] = [
  { name: 'User Edit', path: 'user/1/edit' },
  {
    name: 'Create Article',
    path: 'node/add/article?destination=/admin/content',
  },
  { name: 'Create Page', path: 'node/add/page?destination=/admin/content' },
  { name: 'Content Page', path: 'admin/content' },
  { name: 'Structure Page', path: 'admin/structure' },
  { name: 'Add content type', path: 'admin/structure/types/add' },
  { name: 'Add vocabulary', path: 'admin/structure/taxonomy/add' },
  {
    // Tests long breadcrumb for https://drupal.org/i/3223147.
    name: 'Manage text format, mobile',
    path: 'admin/config/content/formats/manage/restricted_html',
    // Dimensions used by Lighthouse for mobile.
    viewport: { width: 415, height: 823 },
    options: {
      runOnly: {
        type: 'tag',
        values: [
          'wcag2a',
          'wcag2aa',
          'wcag21a',
          'wcag21aa',
          'best-practice',
          'wcag22a',
          'wcag22aa',
        ],
      },
    },
  },
  // @todo Remove the skipped rules below in https://drupal.org/i/3318394.
  {
    name: 'Structure | Block',
    path: 'admin/structure/block',
    options: {
      rules: {
        'color-contrast': { enabled: false },
        'duplicate-id-active': { enabled: false },
        region: { enabled: false },
      },
    },
  },
];

for (const { name, path, options, viewport } of cases) {
  test(
    `NW-a11yTestAdmin-admin-theme scans ${name}`,
    { tag: ['@core', '@local', '@a11y', '@a11y-admin'] },
    async ({ a11yAdminPage }) => {
      await test.step(`Open ${name} as the administrator`, async () => {
        if (viewport) {
          await a11yAdminPage.resize(viewport.width, viewport.height);
        }
        await a11yAdminPage.openForAccessibilityScan(path);
      });

      await test.step('Scan the body for axe violations', async () => {
        const { violations } = await a11yAdminPage.scanAccessibility(
          'body',
          options ?? {},
        );
        expect(violations).toEqual([]);
      });
    },
  );
}
