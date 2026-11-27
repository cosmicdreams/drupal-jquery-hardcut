import type { Locator, Page } from '@playwright/test';

export class PrimaryTabsComponent {
  readonly root: Locator;
  readonly activeTab: Locator;
  readonly inactiveTab: Locator;
  readonly toggle: Locator;
  readonly toggleButton: Locator;
  readonly toggleIcon: Locator;

  constructor(page: Page) {
    // Improvement: Expose the initialized state on the named landmark so
    // tests can track it without coupling to the attachment selector.
    this.root = page
      .getByRole('navigation', { name: 'Primary tabs', exact: true })
      .and(page.locator('[data-drupal-nav-tabs]'));
    // Improvement: Use aria-current on the active task link so the list item
    // state can be derived from the link without inspecting theme classes.
    const tabs = this.root.getByRole('listitem', { includeHidden: true });
    this.activeTab = tabs.and(this.root.locator('.tabs__tab.is-active'));
    // The original Nightwatch visibility assertion resolved the first match.
    this.inactiveTab = tabs
      .and(this.root.locator('.tabs__tab:not(.is-active)'))
      .first();
    // Hidden role queries do not resolve the name of the display:none button.
    // Improvement: Check its accessible name for interaction and use its
    // authored hook to track the same button while hidden.
    this.toggle = this.activeTab.locator('[data-drupal-nav-tabs-trigger]');
    this.toggleButton = this.toggle.and(
      this.activeTab.getByRole('button', {
        name: 'Tabs display toggle',
        exact: true,
      }),
    );
    // The toggle's single decorative SVG has no role or name.
    // Improvement: Give the icon an authored hook if it gains state variants.
    this.toggleIcon = this.toggle.locator('svg');
  }
}
