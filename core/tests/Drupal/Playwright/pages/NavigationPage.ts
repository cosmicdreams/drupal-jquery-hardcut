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
    return submission;
  }

  async resize(width: number, height: number): Promise<void> {
    await this.page.setViewportSize({ width, height });
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
