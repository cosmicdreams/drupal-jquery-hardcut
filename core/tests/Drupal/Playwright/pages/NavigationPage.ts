import { expect } from '@playwright/test';
import type { Locator, Page, Response } from '@playwright/test';
import { NavigationSidebarComponent } from '../components/NavigationSidebarComponent';
import { DrupalPage } from './DrupalPage';

export class NavigationPage extends DrupalPage {
  readonly navigation: NavigationSidebarComponent;
  readonly clearCaches: Locator;
  readonly skipLink: Locator;

  constructor(page: Page) {
    super(page);
    this.navigation = new NavigationSidebarComponent(page);
    this.clearCaches = page.getByRole('button', {
      name: 'Clear all caches',
      exact: true,
    });
    this.skipLink = page.getByRole('link', {
      name: 'Skip to main content',
      exact: true,
      includeHidden: true,
    });
  }

  async submitCacheClear(): Promise<Response> {
    const [submission] = await Promise.all([
      this.page.waitForResponse(
        (response) =>
          response.request().isNavigationRequest() &&
          response.request().method() === 'POST' &&
          new URL(response.url()).pathname.endsWith(
            '/admin/config/development/performance',
          ),
      ),
      this.clearCaches.click(),
    ]);
    // The 303 arrives before the redirected page commits. Wait for the
    // confirmation that only the redirected page renders, so later assertions
    // cannot pass against the page that was open before the submission.
    // Improvement: a role-based locator once the message has a stable role.
    await expect(this.page.getByText('Caches cleared.')).toBeVisible();
    return submission;
  }

  async startWithCollapsedSidebar(): Promise<void> {
    await this.page.addInitScript(() => {
      if (localStorage.getItem('Drupal.navigation.sidebarExpanded') === null) {
        localStorage.setItem('Drupal.navigation.sidebarExpanded', 'false');
      }
    });
  }

  async waitForSidebarAnimation(): Promise<void> {
    await expect(this.document).not.toHaveAttribute(
      'data-admin-toolbar-animating',
      /.*/,
    );
  }

  async pressEscape(): Promise<void> {
    await this.page.keyboard.press('Escape');
  }

  async focusSkipLink(): Promise<void> {
    await this.skipLink.evaluate((link) => (link as HTMLAnchorElement).focus());
  }
}
