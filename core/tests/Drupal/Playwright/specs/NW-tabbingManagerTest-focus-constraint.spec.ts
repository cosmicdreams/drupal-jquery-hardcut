import { expect, test } from '../fixtures/base.fixture';

/**
 * Test tabbingmanager.
 *
 * Tab through the form without tabbing constrained. Tabbing out of the
 * third input should focus the fourth.
 *
 * Tab through the form with tabbing constrained to the container that has
 * the first, second, and third inputs. Tabbing out of the third (final)
 * input should move focus back to the first one.
 *
 * Confirm shift+tab on the first element focuses the third (final).
 *
 * The original active-element callbacks used the lint directive
 * // eslint-disable-next-line func-names, prefer-arrow-callback
 * @see https://www.drupal.org/project/drupal/issues/3229828
 * @see https://www.drupal.org/project/drupal/issues/3264940
 */
test.use({ siteOptions: { modules: ['tabbingmanager_test'] } });

test(
  'NW-tabbingManagerTest-focus-constraint',
  { tag: ['@core', '@local'] },
  async ({ tabbingManagerTestPage: form }) => {
    await test.step('Open the fixture and find the focus constraint root', async () => {
      await form.open('tabbingmanager-test');
      await expect(form.container).toBeAttached();
    });

    await test.step('Tab through the unconstrained form', async () => {
      await form.first.focus();
      await expect(form.first).toBeFocused();
      await form.pressTab();
      await expect(form.second).toBeFocused();
      await form.pressTab();
      await expect(form.third).toBeFocused();
      await form.pressTab();
      await expect(form.fourth).toBeFocused();
    });

    await test.step('Constrain focus and tab through the three inputs', async () => {
      await form.constrainTabbing();
      await form.first.focus();
      await expect(form.first).toBeFocused();
      await form.pressTab();
      await expect(form.second).toBeFocused();
      await form.pressTab();
      await expect(form.third).toBeFocused();
      await form.pressTab();
      await expect(form.first).toBeFocused();
    });

    await test.step('Tab backwards from the first input and wrap to the third', async () => {
      await form.first.focus();
      await expect(form.first).toBeFocused();
      await form.pressShiftTab();
      await expect(form.third).toBeFocused();
    });
  },
);
