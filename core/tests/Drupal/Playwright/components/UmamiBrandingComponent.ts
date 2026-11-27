import type { Locator, Page } from '@playwright/test';

export class UmamiBrandingComponent {
  readonly block: Locator;

  constructor(page: Page) {
    // The block wrapper has no landmark or accessible name. Its configured
    // block ID identifies the exact occurrence the original test guarded.
    // A named landmark or an authored data-* hook on the branding block would
    // provide a better locator.
    this.block = page.locator('#block-umami-branding');
  }
}
