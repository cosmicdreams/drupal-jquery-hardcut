import { expect, test } from '../fixtures/base.fixture';

/**
 * Test JavaScript deprecations.
 * @see https://www.drupal.org/project/drupal/issues/3070521
 * @see https://www.drupal.org/project/drupal/issues/3174884
 * @see https://www.drupal.org/project/drupal/issues/3264940
 * @see https://www.drupal.org/project/drupal/issues/3265617
 */
test.use({ siteOptions: { modules: ['js_deprecation_test'] } });

test(
  'NW-jsDeprecationTest-function-and-property-warnings',
  { tag: ['@core', '@local'] },
  async ({ deprecationTestPage }) => {
    await test.step('Open the deprecation fixture', async () => {
      await deprecationTestPage.open('js_deprecation_test');
      await expect(deprecationTestPage.body).toBeVisible();
      await expect(deprecationTestPage.heading).toContainText(
        'JsDeprecationTest',
      );
    });

    await test.step('Check the function and property deprecation warnings', async () => {
      await expect
        .poll(() => deprecationTestPage.warnings)
        .toContain(
          '[Deprecation] This function is deprecated for testing purposes.',
        );
      await expect
        .poll(() => deprecationTestPage.warnings)
        .toContain(
          '[Deprecation] This property is deprecated for testing purposes.',
        );
    });
  },
);
