import type { Page } from '@playwright/test';
import { DrupalPage } from './DrupalPage';

export class DeprecationTestPage extends DrupalPage {
  // Nightwatch matched '[Deprecation]' anywhere in a warning, so a prefixed
  // message such as 'States: [Deprecation] …' is still caught.
  readonly warnings: string[] = [];

  constructor(page: Page) {
    super(page);
    page.on('console', (message) => {
      if (
        message.type() === 'warning' &&
        message.text().includes('[Deprecation]')
      ) {
        this.warnings.push(message.text());
      }
    });
  }
}
