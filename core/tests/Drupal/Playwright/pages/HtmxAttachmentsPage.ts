import { expect } from '@playwright/test';
import type { Locator, Page, Response } from '@playwright/test';
import { HtmxAttachmentsComponent } from '../components/HtmxAttachmentsComponent';
import { DrupalPage } from './DrupalPage';

export class HtmxAttachmentsPage extends DrupalPage {
  readonly replaceButton: Locator;
  readonly deleteButton: Locator;
  readonly ajaxButton: Locator;
  readonly boostedLink: Locator;
  readonly attachments: HtmxAttachmentsComponent;

  constructor(page: Page) {
    super(page);
    this.replaceButton = page.getByRole('button', {
      name: 'Click this',
      exact: true,
    });
    this.deleteButton = page.getByRole('button', {
      name: 'Delete',
      exact: true,
    });
    this.ajaxButton = page.getByRole('button', {
      name: 'Trigger Ajax',
      exact: true,
    });
    this.boostedLink = page.getByRole('link', {
      name: 'Boosted body',
      exact: true,
    });
    this.attachments = new HtmxAttachmentsComponent(page);
  }

  async openFixture(route: string): Promise<Response | null> {
    return this.open(`htmx-test-attachments/${route}`);
  }

  async followBoostedLink(): Promise<Response> {
    const [response] = await Promise.all([
      this.page.waitForResponse((candidate) =>
        new URL(candidate.url()).pathname.endsWith(
          '/htmx-test-attachments/replace',
        ),
      ),
      this.boostedLink.click(),
    ]);
    return response;
  }

  async clickAjaxButton(): Promise<void> {
    // Ajax binds this form button's mousedown handler during Drupal behavior
    // attachment. Wait for the binding before sending the first pointer input.
    await expect(this.ajaxButton).toHaveAttribute(
      'data-once',
      /\bdrupal-ajax\b/,
    );
    await this.ajaxButton.click();
  }
}
