import type { Locator, Page } from '@playwright/test';
import { AutocompleteComponent } from '../components/AutocompleteComponent';
import { DrupalPage } from './DrupalPage';

export class AutocompleteTestPage extends DrupalPage {
  readonly inlineAutocomplete: AutocompleteComponent;
  readonly denylistAutocomplete: AutocompleteComponent;
  // Any field showing the autocomplete busy state. Nightwatch asserted on the
  // class form-wide; the class is the widget's only busy signal.
  readonly busyFields: Locator;

  constructor(page: Page) {
    super(page);
    this.inlineAutocomplete = new AutocompleteComponent(
      page,
      'Autocomplete 3',
      'label',
    );
    this.denylistAutocomplete = new AutocompleteComponent(
      page,
      'Autocomplete 4',
      'label',
    );
    this.busyFields = page.locator('.is-autocompleting');
  }

  async typeDeniedTerm(): Promise<void> {
    await this.page.clock.install();
    await this.denylistAutocomplete.input.fill('/');
    // Advance the widget's debounce without waiting on wall-clock time.
    await this.page.clock.runFor(1000);
    await this.page.clock.resume();
  }

  async holdSearchRequests(check: () => Promise<void>): Promise<void> {
    let release = () => {};
    const pending = new Promise<void>((resolve) => {
      release = resolve;
    });
    await this.page.route('**/form-test/autocomplete-1?*', async (route) => {
      await pending;
      await route.continue();
    });
    try {
      await check();
    } finally {
      release();
      await this.page.unrouteAll({ behavior: 'wait' });
    }
  }
}
