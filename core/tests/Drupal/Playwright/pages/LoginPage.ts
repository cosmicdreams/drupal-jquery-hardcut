import type { Locator, Page } from '@playwright/test';
import { DrupalPage } from './DrupalPage';

export class LoginPage extends DrupalPage {
  readonly username: Locator;
  readonly password: Locator;
  readonly submit: Locator;

  constructor(page: Page) {
    super(page);
    this.username = page.getByRole('textbox', { name: 'Username' });
    // Password inputs have no implicit role.
    this.password = page.getByLabel('Password', { exact: true });
    this.submit = page.getByRole('button', { name: 'Log in', exact: true });
  }

  async signIn(username: string, password: string): Promise<void> {
    await this.open('user/login');
    await this.username.fill(username);
    await this.password.fill(password);
    await this.submit.click();
    await this.page.waitForURL((url) => !url.pathname.endsWith('/user/login'));
  }

  async deprecationWarnings(): Promise<string[]> {
    return this.page.evaluate(() => {
      const warnings: string[] = JSON.parse(
        sessionStorage.getItem('js_testing_log_test.warnings') ?? '[]',
      );
      return warnings
        .filter((warning) => warning.includes('[Deprecation]'))
        .map((warning) => warning.replace('[Deprecation] ', ''));
    });
  }
}
