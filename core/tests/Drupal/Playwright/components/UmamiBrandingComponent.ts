import type { Locator, Page } from '@playwright/test';

export class UmamiBrandingComponent {
  readonly block: Locator;

  constructor(page: Page) {
    // The block wrapper has no landmark or accessible name. Its configured
    // block ID identifies the exact occurrence the original test guarded.
    this.block = page.locator('#block-umami-branding');
  }
}
