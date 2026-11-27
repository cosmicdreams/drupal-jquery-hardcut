import { expect } from '@playwright/test';
import type { Locator, Page } from '@playwright/test';

export class HtmxAttachmentsComponent {
  readonly content: Locator;
  readonly initializedContent: Locator;
  readonly target: Locator;
  readonly script: Locator;
  readonly stylesheet: Locator;

  constructor(page: Page) {
    // The fixture renders plain containers. Its content class identifies
    // the element that Drupal's behavior changes and that htmx can delete.
    this.content = page.locator('.ajax-content');
    this.initializedContent = this.content.and(
      page.locator('[data-once="htmx-init"]'),
    );
    this.target = page.locator('[data-drupal-htmx-target]');
    // Asset tags are invisible DOM nodes. The fixture library paths identify
    // them independently of a cache-busting query string.
    this.script = page.locator('script[src*="test_htmx/js/behavior.js"]');
    this.stylesheet = page.locator(
      'link[rel="stylesheet"][href*="test_htmx/css/style.css"]',
    );
  }

  async expectAssetsAbsent(): Promise<void> {
    await expect.soft(this.script).toHaveCount(0);
    await expect.soft(this.stylesheet).toHaveCount(0);
  }

  async expectAssetsPresent(): Promise<void> {
    await expect.soft(this.script).toBeAttached();
    await expect.soft(this.stylesheet).toBeAttached();
  }

  async expectVisibleInitializedContent(): Promise<void> {
    await expect.soft(this.content).toBeVisible();
    await expect.soft(this.initializedContent).toBeVisible();
  }
}
