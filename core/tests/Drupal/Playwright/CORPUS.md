# Nightwatch migration corpus

The raw source is in the parent of `949d5a22038`. To verify an assertion's
source or issue history, set `path` to the original file and run the following
commands from the repository root.

```sh
git show "949d5a22038^:$path" | nl -ba
git log --follow 949d5a22038^ -- "$path"
```

## S0

Origins below are relative to `core/tests/Drupal/Nightwatch/Tests/`. The tag
`@core` carries forward the original core test selection.

| Original case | Origin line | Assertion | Playwright spec |
|---|---|---|---|
| Test login | `loginTest.js:20` | Page body is visible | `NW-loginTest-reports-access` |
| Test login | `loginTest.js:21` | Level-one heading contains Reports | `NW-loginTest-reports-access` |
| Test login | `loginTest.js:22` | No JavaScript deprecation warnings were logged | `NW-loginTest-reports-access` |
| Test umami profile | `installProfileTest.js:15` | Test page body is visible | `NW-installProfileTest-umami-branding` |
| Test umami profile | `installProfileTest.js:16` | The Umami branding block exists | `NW-installProfileTest-umami-branding` |
| Test page with langcode | `langcodeTest.js:16` | The document language is French | `NW-langcodeTest-french-document` |

This covers three cases, four explicit assertions and two visibility waits. The
login fixture also carries over the session-cookie assertion from
`Commands/drupalLogin.js`. The database setup creates the same user and grants
the same permissions as the old role and account forms. The old form success
messages were checks on that setup. Login and Reports access verify the seeded
account and permissions. The administrator fixture owns a separate context
and closes it afterwards, taking the place of setup-time sign-out. Playwright
records diagnostics in its report and traces on failure.

## Pending slices

Each pending case and assertion from the following files maps to the pending
corpus entry in [COVERAGE-GAPS.md](COVERAGE-GAPS.md).

| Slice | Original files |
|---|---|
| S1 | `jsOnceTest.js`, `jsDisplace.js`, `tabbingManagerTest.js`, `ajaxExecutionOrderTest.js`, `jsDeprecationTest.js` |
| S2 | `statesTest.js`, `machineNameTransliterationTest.js`, `defaultAdminAutocompleteTest.js` |
| S3 | `htmx/htmxTest.js` |
| S4 | Navigation tests `expandCollapseTest.js`, `navigationDisplaceTest.js`, `navigationMobileFocusTrapTest.js`, and `a11yTest.js` |
| S5 | `defaultAdminDrupalMessageTest.js`, `defaultAdminPrimaryTabsTest.js`, `a11yTestAdmin.js`, `a11yTestDefault.js` |
| S6 | `drupalHtmlBuilderTest.js` |

## Run S0

Start the worktree's DDEV project and run `ddev composer install` at the worktree
root. The worktree needs a writable `sites/simpletest` and a test database.
With the example project and database below, run from `core/`.

```sh
corepack yarn install
corepack yarn playwright install chromium
DRUPAL_TEST_BASE_URL=http://drupal-jquery-hardcut-playwright.ddev.site \
  DRUPAL_TEST_DB_URL=mysql://db:db@db/db \
  corepack yarn test:playwright --workers=3
```

The site factory invokes PHP through DDEV when Playwright runs on the host.
Each test gets a separate database prefix and signed routing cookie. Global
teardown reads this run's prefix records under `sites/simpletest/.playwright/`
and cleans up any site the fixture left behind. The repository root holds
`playwright-report/` and `playwright_output/`, which contains the JUnit file
and retained traces.
