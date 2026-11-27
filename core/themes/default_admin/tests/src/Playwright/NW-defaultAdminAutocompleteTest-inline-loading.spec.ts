import {
  autocompleteSetup,
  expect,
  test,
} from '../../../../../tests/Drupal/Playwright/fixtures/base.fixture';

/**
 * Tests Default Admin autocomplete. Confirm installation.
 *
 * The setup installs Default Admin as the default theme. The form is a
 * front-end route, so it uses that theme for the autocomplete input.
 *
 * The original assertions at lines 41, 45 and 52 used the
 * "eslint-disable-next-line no-unused-expressions" Nightwatch syntax.
 * @see https://www.drupal.org/project/drupal/issues/2651798
 * @see https://www.drupal.org/project/drupal/issues/3151097
 * @see https://www.drupal.org/project/drupal/issues/3166068
 * @see https://www.drupal.org/project/drupal/issues/3174884
 * @see https://www.drupal.org/project/drupal/issues/3264940
 * @see https://www.drupal.org/project/drupal/issues/3277053
 * @see https://www.drupal.org/project/drupal/issues/3371571
 * @see https://www.drupal.org/project/drupal/issues/3418999
 * @see https://www.drupal.org/project/drupal/issues/3560682
 * @see https://www.drupal.org/project/drupal/issues/3582098
 */
test.use({
  siteOptions: { modules: ['form_test'], setupFile: autocompleteSetup },
});

test(
  'NW-defaultAdminAutocompleteTest-inline-loading',
  { tag: ['@core', '@local'] },
  async ({ autocompleteTestPage }) => {
    const { inlineAutocomplete, denylistAutocomplete } = autocompleteTestPage;

    await test.step('Open the Default Admin autocomplete form', async () => {
      await autocompleteTestPage.open('form-test/autocomplete');
      await expect(autocompleteTestPage.body).toBeVisible();
      await expect(denylistAutocomplete.input).toBeVisible();
    });

    await autocompleteTestPage.holdSearchRequests(async () => {
      // Tests that entering a character from the
      // data-autocomplete-first-character-denylist doesn't start the
      // autocomplete process.
      await test.step('Keep the throbber off for a denied first character', async () => {
        await autocompleteTestPage.typeDeniedTerm();
        await expect(denylistAutocomplete.input).not.toHaveClass(
          /(^|\s)is-autocompleting(\s|$)/,
        );
      });

      // Tests both the autocomplete-message nor the autocomplete dropdown are
      // present when nothing has been entered in autocomplete-3.
      await test.step('Keep the loading message and suggestions hidden before typing', async () => {
        await expect(inlineAutocomplete.loading).toBeAttached();
        await expect(inlineAutocomplete.loading).toBeHidden();
        await expect(inlineAutocomplete.suggestions).toBeAttached();
        await expect(inlineAutocomplete.suggestions).toBeHidden();
      });

      // Tests that upon entering some text in autocomplete-3, first the
      // autocomplete-message appears and then the autocomplete dropdown with a
      // result. At that point the autocomplete-message should be invisible again.
      await test.step('Show the loading message while the search is pending', async () => {
        await inlineAutocomplete.input.fill('123');
        await expect(inlineAutocomplete.loading).toBeVisible();
        await expect(inlineAutocomplete.suggestions).toBeHidden();
      });
    });

    await test.step('Show the result and hide the loading message', async () => {
      await expect(inlineAutocomplete.result).toBeVisible();
      await expect(inlineAutocomplete.loading).toBeHidden();
    });
  },
);
