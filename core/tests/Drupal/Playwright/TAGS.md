# Playwright tags

Apply tags in the test options, such as `{ tag: ['@core', '@local'] }`.
Use `--grep` or `--grep-invert` to select tests by tag.

| Tag              | Meaning                                  | Why it exists                                                                             | Unlock signal                                                                                   |
| ---------------- | ---------------------------------------- | ----------------------------------------------------------------------------------------- | ----------------------------------------------------------------------------------------------- |
| `@core`          | Shared core behavior                     | Preserves selection of the original core tests apart from module and theme suites.        | Retire when Playwright projects provide the same split.                                         |
| `@ajax`          | Ajax command behavior                    | Preserves selection of the original Ajax tests.                                           | Retire when a Playwright project provides the same selection.                                   |
| `@htmx`          | htmx behavior and assets                 | Preserves selection of the original htmx cases.                                           | Retire when a Playwright project provides the same selection.                                   |
| `@navigation`    | Navigation sidebar behavior              | Selects the original Navigation suite.                                                    | Retire when Playwright projects provide the same selection.                                     |
| `@a11y`          | Browser accessibility scans              | Selects checks that report axe violations.                                                | Retire when an accessibility project provides the same selection.                               |
| `@default-admin` | Default Admin behavior                   | Selects the original Default Admin theme suite.                                           | Retire when a Playwright project provides the same selection.                                   |
| `@a11y-default`  | Default theme accessibility scans        | Selects axe checks for public pages.                                                      | Retire when an accessibility project separates administrative and public pages.                 |
| `@a11y-admin`    | Administrative accessibility scans       | Selects axe checks for administration pages.                                              | Retire when an accessibility project separates administrative and public pages.                 |
| `@ckeditor5`     | CKEditor 5 behavior                      | Preserves selection of the original CKEditor 5 unit tests.                                | Retire when a Playwright project provides the same selection.                                   |
| `@local`         | Requires a disposable local installation | The fixture creates and removes a database and authenticates against the site it created. | Retire when the runner can provision and authenticate against a disposable remote installation. |

Run `node core/tests/Drupal/Playwright/support/check-tags.mjs` from the repository root to check both directions.
