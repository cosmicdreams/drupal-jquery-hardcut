import type { Locator, Page } from '@playwright/test';
import { MessageAreaComponent } from '../components/MessageAreaComponent';
import { DrupalPage } from './DrupalPage';

export class MessageTestPage extends DrupalPage {
  readonly content: Locator;
  readonly defaultMessages: MessageAreaComponent;
  readonly customMessages: MessageAreaComponent;

  constructor(page: Page) {
    super(page);
    // Improvement: Name a landmark on the exact theme content wrapper and
    // expose the message destinations and fixture controls as named regions.
    this.content = page.locator('.region-content');
    this.defaultMessages = new MessageAreaComponent(
      page.locator('[data-drupal-messages] > .messages__wrapper'),
      page.locator('[data-drupal-messages-area=""]'),
    );
    this.customMessages = new MessageAreaComponent(
      page.locator('[data-drupal-messages-other]'),
      page.locator(
        '[data-drupal-messages-area="[data-drupal-messages-other]"]',
      ),
    );
  }

  async openWithSystemMessages(): Promise<void> {
    await this.open('js_message_test_link_with_system_messages');
  }
}
