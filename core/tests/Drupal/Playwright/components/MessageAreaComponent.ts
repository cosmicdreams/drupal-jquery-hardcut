import type { Locator } from '@playwright/test';

export class MessageAreaComponent {
  readonly items: Locator;
  readonly status: Locator;
  readonly warning: Locator;
  readonly error: Locator;
  readonly ready: Locator;
  readonly addStatus: Locator;
  readonly addWarning: Locator;
  readonly addError: Locator;

  constructor(
    readonly root: Locator,
    controls: Locator,
  ) {
    // Improvement: Give messages a common authored hook for direct children
    // and expose severity on both the server and client message markup.
    this.items = root.locator(':scope > .messages-list__item');
    this.status = root.locator(':scope > .messages--status');
    this.warning = root.locator(':scope > .messages--warning');
    this.error = root.locator(':scope > .messages--error');
    // Improvement: Expose a ready event when the fixture binds the controls.
    this.ready = controls.and(
      controls.page().locator('[data-once~="messages-details"]'),
    );
    this.addStatus = controls.getByRole('button', {
      name: 'Add status',
      exact: true,
    });
    this.addWarning = controls.getByRole('button', {
      name: 'Add warning',
      exact: true,
    });
    this.addError = controls.getByRole('button', {
      name: 'Add error',
      exact: true,
    });
  }

  async addMessages(): Promise<void> {
    await this.addStatus.click();
    await this.addWarning.click();
    await this.addError.click();
  }
}
