import type { Locator, Page } from '@playwright/test';
import { DrupalPage } from './DrupalPage';

declare const Drupal: {
  tabbingManager: {
    constrain(element: Element, options: { trapFocus: boolean }): void;
  };
};

export class TabbingManagerTestPage extends DrupalPage {
  readonly container: Locator;
  readonly first: Locator;
  readonly second: Locator;
  readonly third: Locator;
  readonly fourth: Locator;

  constructor(page: Page) {
    super(page);
    // The constraint root is a plain div with an authored ID.
    this.container = page.locator('#tabbingmanager-test-container');
    this.first = page.getByRole('textbox', { name: 'First', exact: true });
    this.second = page.getByRole('textbox', { name: 'Second', exact: true });
    this.third = page.getByRole('textbox', { name: 'Third', exact: true });
    this.fourth = page.getByRole('textbox', { name: 'Fourth', exact: true });
  }

  async constrainTabbing(): Promise<void> {
    await this.container.evaluate((container) => {
      Drupal.tabbingManager.constrain(container, { trapFocus: true });
    });
  }

  async pressTab(): Promise<void> {
    await this.page.keyboard.press('Tab');
  }

  async pressShiftTab(): Promise<void> {
    await this.page.keyboard.press('Shift+Tab');
  }
}
