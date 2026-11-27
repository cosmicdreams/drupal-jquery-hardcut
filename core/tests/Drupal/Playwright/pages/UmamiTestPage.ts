import type { Page } from '@playwright/test';
import { UmamiBrandingComponent } from '../components/UmamiBrandingComponent';
import { DrupalPage } from './DrupalPage';

export class UmamiTestPage extends DrupalPage {
  readonly branding: UmamiBrandingComponent;

  constructor(page: Page) {
    super(page);
    this.branding = new UmamiBrandingComponent(page);
  }
}
