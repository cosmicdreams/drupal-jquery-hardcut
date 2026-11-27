import type { Locator, Page } from '@playwright/test';
import { DrupalPage } from './DrupalPage';

export class AjaxPromiseFormPage extends DrupalPage {
  readonly executeCommands: Locator;
  readonly output: Locator;

  constructor(page: Page) {
    super(page);
    this.executeCommands = page.getByRole('button', {
      name: 'Execute commands button',
      exact: true,
    });
    // The Ajax wrapper is a plain div with an authored ID.
    this.output = page.locator('#ajax_test_form_promise_wrapper');
  }
}
