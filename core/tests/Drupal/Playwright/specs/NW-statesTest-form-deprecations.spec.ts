import { expect, test } from '../fixtures/base.fixture';

/**
 * Covers deprecations on the full Form API States fixture.
 *
 * JavascriptStatesTest::testNumberTrigger() covers the initial visibility and
 * trusted spinner interaction on this route. The Nightwatch spinner comment
 * described its synthetic change event:
 *
 * Emulate usage of the spinner browser widget on number inputs
 * on modern browsers.
 * @see https://www.drupal.org/project/drupal/issues/2995570
 * @see https://www.drupal.org/project/drupal/issues/3070521
 * @see https://www.drupal.org/project/drupal/issues/3174884
 * @see https://www.drupal.org/project/drupal/issues/3181634
 * @see https://www.drupal.org/project/drupal/issues/3264940
 */
test.use({ siteOptions: { modules: ['form_test'] } });

test(
  'NW-statesTest-form-deprecations',
  { tag: ['@core', '@local'] },
  async ({ deprecationTestPage }) => {
    await test.step('Open the States form', async () => {
      const response = await deprecationTestPage.open(
        'form-test/javascript-states-form',
      );
      expect(response?.ok()).toBe(true);
      await expect(deprecationTestPage.body).toBeVisible();
    });

    await test.step('Check that form attachment logs no deprecations', async () => {
      expect(deprecationTestPage.warnings).toEqual([]);
    });
  },
);
