import { AxeBuilder } from '@axe-core/playwright';
import type { Locator, Page, Response } from '@playwright/test';

export class DrupalPage {
  readonly body: Locator;
  readonly heading: Locator;
  readonly document: Locator;

  constructor(protected readonly page: Page) {
    // Body and document language are document-level checks with no role.
    this.body = page.locator('body');
    this.document = page.locator('html');
    this.heading = page.getByRole('heading', { level: 1 });
  }

  async open(path: string): Promise<Response | null> {
    return this.page.goto(path);
  }

  async openForAccessibilityScan(path: string): Promise<void> {
    const response = await this.open(path);
    if (!response?.ok() || response.request().redirectedFrom()) {
      // The response must be the requested page, including its query string.
      throw new Error(
        `Could not open ${path} for an accessibility scan (HTTP ${response?.status()}, ${response?.url()}).`,
      );
    }
  }

  async resize(width: number, height: number): Promise<void> {
    await this.page.setViewportSize({ width, height });
  }

  async scanAccessibility(
    context = 'body',
    options: Parameters<AxeBuilder['options']>[0] = {},
  ): ReturnType<AxeBuilder['analyze']> {
    return (
      new AxeBuilder({ page: this.page })
        .include(context)
        .options(options)
        // Match Nightwatch’s axe.run() scope, including same-origin frames.
        .setLegacyMode()
        .analyze()
    );
  }

  async hasSession(): Promise<boolean> {
    const cookies = await this.page.context().cookies(this.page.url());
    return cookies.some(({ name }) => /^S?SESS/.test(name));
  }
}
