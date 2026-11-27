import { expect, test } from '../fixtures/base.fixture';

/**
 * Test simple once call.
 *
 * Check that follow-up calls to once return an empty array.
 *
 * The original chained callback started with // prettier-ignore.
 * @see https://www.drupal.org/project/drupal/issues/3207782
 * @see https://www.drupal.org/project/drupal/issues/3264940
 * @see https://www.drupal.org/project/drupal/issues/3268550
 */
test.use({ siteOptions: { modules: ['js_once_test'] } });

test(
  'NW-jsOnceTest-mark-and-remove',
  { tag: ['@core', '@local'] },
  async ({ onceTestPage }) => {
    await test.step('Open the once fixture and wait for an item', async () => {
      await onceTestPage.open('js_once_test');
      await expect(onceTestPage.firstItem).toBeVisible();
    });

    await test.step('Mark all five items once', async () => {
      expect(await onceTestPage.markItems('all')).toBe(5);
      expect(await onceTestPage.markItems('all')).toBe(0);
    });

    await test.step('Mark two items once under a separate ID', async () => {
      expect(await onceTestPage.markItems('extra')).toBe(2);
      expect(await onceTestPage.markItems('extra')).toBe(0);
    });

    await test.step('Remove the first mark from all five items once', async () => {
      expect(await onceTestPage.removeItems('all')).toBe(5);
      expect(await onceTestPage.removeItems('all')).toBe(0);
    });

    await test.step('Remove the separate mark from two items once', async () => {
      expect(await onceTestPage.removeItems('extra')).toBe(2);
      expect(await onceTestPage.removeItems('extra')).toBe(0);
    });
  },
);
