# Playwright tags

Apply tags in the test options, such as `{ tag: ['@core', '@local'] }`.
Use `--grep` or `--grep-invert` to select tests by tag.

| Tag | Meaning | Why it exists | Unlock signal |
|---|---|---|---|
| `@core` | Shared core behavior | Preserves selection of the original core tests apart from module and theme suites. | Retire when Playwright projects provide the same split. |
| `@ajax` | Ajax command behavior | Preserves selection of the original Ajax tests. | Retire when a Playwright project provides the same selection. |
| `@local` | Requires a disposable local installation | The fixture creates and removes a database and authenticates against the site it created. | Retire when the runner can provision and authenticate against a disposable remote installation. |

Run `node core/tests/Drupal/Playwright/support/check-tags.mjs` from the repository root to check both directions.
