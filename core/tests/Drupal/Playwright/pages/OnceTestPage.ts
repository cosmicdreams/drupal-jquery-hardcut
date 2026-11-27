import type { Locator, Page } from '@playwright/test';
import { DrupalPage } from './DrupalPage';

type OnceOperation = (id: string, selector: string) => Element[];

declare const once: OnceOperation & { remove: OnceOperation };

const itemSelector = '[data-drupal-item]';
const groups = {
  all: { id: 'js_once_test', selector: itemSelector },
  extra: {
    id: 'js_once_test_extra',
    selector: '[data-drupal-item="1"],[data-drupal-item="2"]',
  },
};

export class OnceTestPage extends DrupalPage {
  readonly firstItem: Locator;

  constructor(page: Page) {
    super(page);
    // The fixture renders plain divs with authored data attributes.
    this.firstItem = page.locator(itemSelector).first();
  }

  async markItems(group: 'all' | 'extra'): Promise<number> {
    return this.page.evaluate(
      ({ id, selector }) => once(id, selector).length,
      groups[group],
    );
  }

  async removeItems(group: 'all' | 'extra'): Promise<number> {
    return this.page.evaluate(
      ({ id, selector }) => once.remove(id, selector).length,
      groups[group],
    );
  }
}
