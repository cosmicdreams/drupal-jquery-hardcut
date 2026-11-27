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

  async hasSession(): Promise<boolean> {
    const cookies = await this.page.context().cookies(this.page.url());
    return cookies.some(({ name }) => /^S?SESS/.test(name));
  }
}
