import type { Page } from '@playwright/test';
import { DrupalPage } from './DrupalPage';

export class DeprecationTestPage extends DrupalPage {
  readonly warnings: string[] = [];

  constructor(page: Page) {
    super(page);
    page.on('console', (message) => {
      if (
        message.type() === 'warning' &&
        message.text().startsWith('[Deprecation] ')
      ) {
        this.warnings.push(message.text());
      }
    });
  }
}
