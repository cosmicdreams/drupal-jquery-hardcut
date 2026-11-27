import type { Page } from '@playwright/test';
import { PrimaryTabsComponent } from '../components/PrimaryTabsComponent';
import { DrupalPage } from './DrupalPage';

export class PrimaryTabsTestPage extends DrupalPage {
  readonly primaryTabs: PrimaryTabsComponent;

  constructor(page: Page) {
    super(page);
    this.primaryTabs = new PrimaryTabsComponent(page);
  }

  async openArticle(width: number): Promise<void> {
    await this.page.setViewportSize({ width, height: 800 });
    await this.open('node/1');
  }
}
