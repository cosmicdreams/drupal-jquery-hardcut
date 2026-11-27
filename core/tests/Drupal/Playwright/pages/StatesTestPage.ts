import type { Locator, Page } from '@playwright/test';
import { DeprecationTestPage } from './DeprecationTestPage';

export class StatesTestPage extends DeprecationTestPage {
  readonly conditionalTextfield: Locator;
  readonly numberTrigger: Locator;
  readonly spinnerDependentItem: Locator;

  constructor(page: Page) {
    super(page);
    // Identify the textfield by its authored form name even when hidden.
    this.conditionalTextfield = page.locator('input[name="textfield"]');
    this.numberTrigger = page.getByRole('spinbutton', {
      name: 'Number trigger',
      exact: true,
    });
    // The dependent item has no role and must be located while hidden.
    this.spinnerDependentItem = page.locator(
      '#edit-item-visible-when-number-trigger-filled-by-spinner',
    );
  }

  async incrementNumberTrigger(): Promise<void> {
    // Exercise the native change event independently of the States keyup handler.
    await this.numberTrigger.evaluate((input) => {
      input.addEventListener(
        'keyup',
        (event) => event.stopImmediatePropagation(),
        {
          capture: true,
          once: true,
        },
      );
    });
    await this.numberTrigger.press('ArrowUp');
    await this.numberTrigger.press('Tab');
  }
}
