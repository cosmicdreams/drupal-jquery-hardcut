import { expect, test } from '../fixtures/base.fixture';

/**
 * Test Drupal.displace() JavaScript API.
 *
 * Both original callbacks used the lint directive
 * // eslint-disable-next-line func-names, prefer-arrow-callback, no-shadow
 * @see https://www.drupal.org/project/drupal/issues/3256768
 */
test.use({ siteOptions: { modules: ['js_displace'] } });

test(
  'NW-jsDisplace-viewport-offsets',
  { tag: ['@core', '@local'] },
  async ({ displaceTestPage }) => {
    await test.step('Open the front page and add four fixed elements', async () => {
      await displaceTestPage.open('./');
      await expect(displaceTestPage.body).toBeVisible();
      await displaceTestPage.addOffsetElements();
    });

    await test.step('Calculate viewport displacement for all four edges', async () => {
      const offsets = await displaceTestPage.displace();
      expect(offsets.top).toBe(200);
      expect(offsets.right).toBe(110);
      expect(offsets.bottom).toBe(145);
      expect(offsets.left).toBe(310);
    });

    await test.step('Read the four computed CSS custom properties', async () => {
      const offsets = await displaceTestPage.cssOffsets();
      expect(offsets.top).toBe('200px');
      expect(offsets.right).toBe('110px');
      expect(offsets.bottom).toBe('145px');
      expect(offsets.left).toBe('310px');
    });
  },
);
