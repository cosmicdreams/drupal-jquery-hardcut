import type { Locator, Page } from '@playwright/test';

export class AutocompleteComponent {
  readonly input: Locator;
  readonly loading: Locator;
  readonly suggestions: Locator;
  readonly result: Locator;

  constructor(page: Page, name: string, result: string) {
    this.input = page.getByRole('combobox', { name, exact: true });
    // Default Admin places the visual loading text alongside the input in
    // its dedicated wrapper. The text has no landmark or status role.
    // A role="status" (or a stable hook) on .admin-autocomplete__message would
    // replace this text locator and announce the message to screen readers.
    this.loading = this.input.locator('..').getByText('Loading…', {
      exact: true,
    });
    this.suggestions = page.getByRole('listbox', {
      name,
      exact: true,
      includeHidden: true,
    });
    this.result = this.suggestions.getByRole('option', {
      name: result,
      exact: true,
    });
  }
}
