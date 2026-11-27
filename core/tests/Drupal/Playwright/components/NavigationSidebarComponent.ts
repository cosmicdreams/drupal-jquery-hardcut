import { expect } from '@playwright/test';
import type { Locator, Page } from '@playwright/test';

export class NavigationSidebarComponent {
  readonly sidebar: Locator;
  readonly desktopToggle: Locator;
  readonly desktopExpandedToggle: Locator;
  readonly mobileToggle: Locator;
  readonly closeButton: Locator;
  readonly configuration: Locator;
  readonly create: Locator;
  readonly overlay: Locator;
  readonly displacePlaceholder: Locator;
  readonly outsideElements: Locator;
  readonly inertMarkers: Locator;

  constructor(page: Page) {
    const toggleName = /^(Expand|Collapse) sidebar$/;
    // The outer sidebar is presentational. Use the ID named by aria-controls.
    this.sidebar = page.locator('#admin-toolbar');
    const navigation = this.sidebar.getByRole('navigation', {
      name: 'Administrative sidebar',
      includeHidden: true,
    });
    // The authored footer ID separates the desktop toggle from the close button.
    this.desktopToggle = navigation
      .locator('#menu-footer')
      .getByRole('button', { name: toggleName, includeHidden: true });
    this.desktopExpandedToggle = this.desktopToggle.and(
      page.locator('[aria-expanded="true"]'),
    );
    this.mobileToggle = page
      .getByRole('button', { name: toggleName, includeHidden: true })
      .and(
        page.locator('[data-drupal-selector="admin-toolbar-mobile-trigger"]'),
      );
    this.closeButton = navigation.getByRole('button', {
      name: 'Collapse sidebar',
      exact: true,
    });
    this.configuration = navigation.getByRole('button', {
      name: 'Extend Configuration',
      exact: true,
      includeHidden: true,
    });
    this.create = navigation.getByRole('button', {
      name: 'Extend Create',
      exact: true,
    });
    // The overlay uses the authored trigger attribute. The zero-size
    // displacement placeholder has no role or authored hook. Improvement: give
    // the placeholder a data-drupal-selector hook in the Navigation template.
    this.overlay = page.locator('[admin-toolbar-trigger]');
    this.displacePlaceholder = this.sidebar.locator(
      '.admin-toolbar__displace-placeholder',
    );
    // Preserve the original population of direct body children. The control
    // bar has no role or authored hook on its wrapper. Improvement: give the
    // control-bar wrapper a data-drupal-selector hook in its template.
    this.outsideElements = page.locator(
      'body > :not(#admin-toolbar, [admin-toolbar-trigger], .admin-toolbar-control-bar)',
    );
    this.inertMarkers = page.locator('[data-admin-toolbar-inert]');
  }

  async clickOverlay(): Promise<void> {
    // The sidebar covers the left side of the overlay. Click the exposed edge.
    const x = await this.overlay.evaluate(
      (overlay) => overlay.clientWidth - 10,
    );
    await this.overlay.click({ position: { x, y: 10 } });
  }

  async outsideElementsMissing(attribute: string): Promise<number> {
    return this.outsideElements.evaluateAll(
      (elements, name) =>
        elements.filter((element) => !element.hasAttribute(name)).length,
      attribute,
    );
  }

  async openOnMobile(): Promise<void> {
    await expect(this.mobileToggle).toHaveAttribute(
      'data-once',
      /\badmin-toolbar-trigger\b/,
    );
    await this.mobileToggle.click();
  }
}
