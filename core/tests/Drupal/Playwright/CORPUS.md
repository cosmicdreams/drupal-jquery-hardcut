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

This covers three Nightwatch cases. The Reports fixture retains the
session-cookie assertion from `Commands/drupalLogin.js:26` and checks
deprecation warnings on the page that signed in. A separate
`PW-login-reports-session-isolation` test checks that the default page stays
anonymous when the Reports page signs in. The French test also checks the
navigation response before reading the document language.

### Nightwatch login helper assertions

Origins below are relative to `core/tests/Drupal/Nightwatch/Commands/`.
The Reports fixture seeds the account, role, and permissions.

| Origin | Assertion | Disposition | PHPUnit coverage |
|---|---|---|---|
| `drupalCreateRole.js:34` | The generated role machine name appears | Harness setup, not ported | `MachineNameTransliterationTest::testMachineNameTransliterations()` checks the generated preview on the add-role form |
| `drupalCreateRole.js:39` | The role-added message appears | Harness setup, not ported | `UserRoleAdminTest::testRoleAdministration()` checks the message after submitting the add-role form |
| `drupalCreateRole.js:44` | The permissions table appears | Harness setup, not ported | `UserPermissionsTest::testPermissionTableHtml()` checks the cell count of a module header row. See [coverage gaps](COVERAGE-GAPS.md) for the table selector and visibility. |
| `drupalCreateRole.js:57` | The permissions-saved message appears | Harness setup, not ported | `UserPermissionsTest::testUserPermissionChanges()` checks the message after saving |
| `drupalCreateUser.js:35,40` | The password input is visible | Harness setup, not ported | See [coverage gaps](COVERAGE-GAPS.md) |
| `drupalCreateUser.js:49,54–57` | The account-created message appears | Harness setup, not ported | `UserCreateTest::testUserAdd()` checks the confirmation after submitting the form |
| `drupalLogout.js:24` | Logout confirmation removes the browser session cookie | Harness setup, not ported | See [coverage gaps](COVERAGE-GAPS.md) |

## S1

Origins are relative to `core/tests/Drupal/Nightwatch/Tests/`. Each original
has one case and uses the per-test site fixture.

| Origin | Assertion | Disposition | Playwright spec |
|---|---|---|---|
| `jsOnceTest.js:12` | The first fixture item is visible | Port faithfully | `NW-jsOnceTest-mark-and-remove` |
| `jsOnceTest.js:19` | The first mark returns five items | Port faithfully | `NW-jsOnceTest-mark-and-remove` |
| `jsOnceTest.js:32` | The repeated mark returns zero items | Port faithfully | `NW-jsOnceTest-mark-and-remove` |
| `jsOnceTest.js:47` | A separate mark on a subset returns two items | Port faithfully | `NW-jsOnceTest-mark-and-remove` |
| `jsOnceTest.js:62` | Repeating the separate mark returns zero items | Port faithfully | `NW-jsOnceTest-mark-and-remove` |
| `jsOnceTest.js:74` | Removing the first mark returns five items | Port faithfully | `NW-jsOnceTest-mark-and-remove` |
| `jsOnceTest.js:86` | Repeating removal of the first mark returns zero items | Port faithfully | `NW-jsOnceTest-mark-and-remove` |
| `jsOnceTest.js:101` | Removing the separate mark returns two items | Port faithfully | `NW-jsOnceTest-mark-and-remove` |
| `jsOnceTest.js:116` | Repeating removal of the separate mark returns zero items | Port faithfully | `NW-jsOnceTest-mark-and-remove` |
| `jsDisplace.js:69` | The front-page body is visible | Port faithfully | `NW-jsDisplace-viewport-offsets` |
| `jsDisplace.js:80–83, 88` | Returned top, right, bottom, and left offsets are 200, 110, 145, 310 | Port faithfully | `NW-jsDisplace-viewport-offsets` |
| `jsDisplace.js:111–114, 119` | Computed custom properties report 200px, 110px, 145px, 310px | Port faithfully | `NW-jsDisplace-viewport-offsets` |
| `tabbingManagerTest.js:12` | The constraint container exists | Port faithfully | `NW-tabbingManagerTest-focus-constraint` |
| `tabbingManagerTest.js:25, 40, 55, 70` | Unconstrained focus goes from First to Second, Third, and Fourth | Port faithfully | `NW-tabbingManagerTest-focus-constraint` |
| `tabbingManagerTest.js:94, 109, 124, 139` | Constrained focus goes from First to Second and Third, then wraps to First | Port faithfully | `NW-tabbingManagerTest-focus-constraint` |
| `tabbingManagerTest.js:157, 172` | Focus starts on First and Shift+Tab wraps to Third | Port faithfully | `NW-tabbingManagerTest-focus-constraint` |
| `ajaxExecutionOrderTest.js:12` | The promise form page body is visible | Port faithfully | `NW-ajaxExecutionOrderTest-promise-command-order` |
| `ajaxExecutionOrderTest.js:14` | The wrapper is visible after clicking the execute button | Port faithfully | `NW-ajaxExecutionOrderTest-promise-command-order` |
| `ajaxExecutionOrderTest.js:15` | The command output contains 12345 in order | Port faithfully | `NW-ajaxExecutionOrderTest-promise-command-order` |
| `jsDeprecationTest.js:12` | The fixture page body is visible | Port faithfully | `NW-jsDeprecationTest-function-and-property-warnings` |
| `jsDeprecationTest.js:13` | The main heading contains JsDeprecationTest | Port faithfully | `NW-jsDeprecationTest-function-and-property-warnings` |
| `jsDeprecationTest.js:14–16` | Calling a deprecated function emits its tagged warning | Adapt | `NW-jsDeprecationTest-function-and-property-warnings` |
| `jsDeprecationTest.js:17–19` | Reading a deprecated property emits its tagged warning | Adapt | `NW-jsDeprecationTest-function-and-property-warnings` |

The public `once`, `Drupal.displace()`, `Drupal.tabbingManager.constrain()`,
and Ajax promise ordering contracts survive the hard cut. The tabbing test
drives the real keyboard and uses the accessible textbox names. The fixture
fields use Drupal's `#id` so their visible labels name the inputs. The module
installer resolves dependencies, including `dialog_test` for `ajax_test`.

`Drupal.deprecationError()` and `Drupal.deprecatedProperty()` still emit
`console.warn` with the `[Deprecation]` prefix. Playwright subscribes before
navigation and checks the original messages directly on the browser console.
Nightwatch read the same warnings through its session-storage logger.

`PW-displace-native-viewport-offset-event` also guards the document
`CustomEvent` and its four offsets in `detail`. Commit `9b60ef69556` replaced
the jQuery broadcast with this native contract. The original displacement
case checked return values and computed styles.

All five original cases have coverage. S1 has no retirements.

## S2

<!-- cspell:ignore Äwesome dnoooooxouuuuythss dnoooooxouuuuuthss ÐÑÒÓÔÕÖ ØÙÚÛÜÝ -->

Core origins are relative to `core/tests/Drupal/Nightwatch/Tests/`.
The Default Admin original is in `core/themes/default_admin/tests/src/Nightwatch/`.
Each Playwright case has an isolated site. The installer provisions `form_test`
and its dependencies for all three cases. The autocomplete setup selects Default
Admin as the default theme and signs in a disposable account with
`access autocomplete test` through the shared fixture.

| Origin | Assertion | Disposition | Coverage |
|---|---|---|---|
| `statesTest.js:12,19` | The full States form page body is visible | Port faithfully. Both visits used the same fixture and initial state. | `NW-statesTest-form-deprecations` |
| `statesTest.js:13` | The textfield controlled by an OR condition starts hidden | Adapt. Existing browser coverage uses the same route and verifies both inputs in the condition. | `JavascriptStatesTest::testNumberTrigger()` |
| `statesTest.js:14` | The full States form logs no deprecation warnings | Adapt. Subscribe to the browser console for Drupal's `[Deprecation]` warnings. | `NW-statesTest-form-deprecations` |
| `statesTest.js:20,32` | The dependent item starts hidden and appears after the number spinner changes | Adapt. Existing browser coverage sends Arrow Up and Tab, suppresses key-release handlers, and checks the trusted change event. | `JavascriptStatesTest::testNumberTrigger()` |
| `machineNameTransliterationTest.js:231` | The filtered module checkbox is visible as part of setup | Adapt. Provision the module through the installer and verify its fixture is reachable. | `NW-machineNameTransliterationTest-configured-transliteration` |
| `machineNameTransliterationTest.js:257`, vectors at lines 8, 15 | English `Bob → bob` and `Äwesome → awesome` through `Drupal.behaviors.machineName.transliterate()` with the original settings | Port. | `NW-machineNameTransliterationTest-configured-transliteration` |
| `machineNameTransliterationTest.js:257`, vector at line 59 | English uppercase Latin-1 transliteration through the same call and settings | Port, expected value updated. Nightwatch expected `dnoooooxouuuuuthss`; transliteration 2.7.0 (`5776d7ded4d`, merged by `abf9f022c68`) maps `Ý` to `y`, giving `dnoooooxouuuuythss`. | `NW-machineNameTransliterationTest-configured-transliteration` |
| `machineNameTransliterationTest.js:257`, other 28 vectors | Exact output for each input with its original replacement pattern, character, and maximum length | Port faithfully. The same synchronous browser method accepts the original settings. | `NW-machineNameTransliterationTest-configured-transliteration` |
| `defaultAdminAutocompleteTest.js:12` | The themes list is visible after setup | Adapt. The installer selects Default Admin and the spec confirms its loading markup on the fixture. | `NW-defaultAdminAutocompleteTest-inline-loading` |
| `defaultAdminAutocompleteTest.js:28` | The form page body is visible | Port faithfully. | `NW-defaultAdminAutocompleteTest-inline-loading` |
| `defaultAdminAutocompleteTest.js:36` | Entering `/` in the denylisted field does not start the throbber | Adapt. Check the same class after advancing the browser clock through the debounce while requests are held. | `NW-defaultAdminAutocompleteTest-inline-loading` |
| `defaultAdminAutocompleteTest.js:44` | The loading message starts hidden | Adapt. The Default Admin loading text identifies the message after removal of its old data selector. | `NW-defaultAdminAutocompleteTest-inline-loading` |
| `defaultAdminAutocompleteTest.js:46` | The suggestions start hidden | Adapt. Use the native listbox named for the combobox. | `NW-defaultAdminAutocompleteTest-inline-loading` |
| `defaultAdminAutocompleteTest.js:55–57` | The loading message appears before suggestions | Adapt. Hold the request until the message is visible and the listbox is still hidden. | `NW-defaultAdminAutocompleteTest-inline-loading` |
| `defaultAdminAutocompleteTest.js:58` | The suggestions appear | Adapt. Release the request and check the named option in the native listbox. | `NW-defaultAdminAutocompleteTest-inline-loading` |
| `defaultAdminAutocompleteTest.js:61` | The loading message disappears after suggestions appear | Adapt. Check the same loading text after the server result appears. | `NW-defaultAdminAutocompleteTest-inline-loading` |

Commit `20cd593597b` moved States to native conditions, `79f64edb511` updated
the fixture selectors, and `1f5fd93f2f3` removed the compatibility bridges.
`NativeStatesPublicationTest::testNativeNumberTriggerNoDeprecations()` covers
the separate `/states-test/native-form` route. The Playwright deprecation guard
uses the original, larger `/form-test/javascript-states-form` fixture.

Commit `30270eb59f1` converted machine-name field events to native events.
The Playwright case exercises inputs and exact settings beyond the browser
preview checks in `MachineNameTest::testMachineName()` and
`MachineNameTest::testReplacementAndTruncation()`.

Commit `fc35bee38e1` replaced the jQuery UI autocomplete menu and changed the
loading markup to use the input's busy class. Its idle styles lost to
`.container-inline div` in Default Admin. The scoped theme rule restores hidden
loading text before and after the request. `DialogTest::testAutocompleteKeyboardAndRequests()`
covers request suppression in a modal. The Playwright case covers the themed
throbber and the inline message lifecycle on the standalone form.
The file history also includes #3174884, #3264940, and #3560682.

The native selectors carry the original user-visible contracts. S2 has no
retirements, new `PW-` cases, or uncovered assertions.

## Pending slices

Each pending case and assertion from the following files maps to the pending
corpus entry in [COVERAGE-GAPS.md](COVERAGE-GAPS.md).

| Slice | Original files |
|---|---|
| S3 | `htmx/htmxTest.js` |
| S4 | Navigation tests `expandCollapseTest.js`, `navigationDisplaceTest.js`, `navigationMobileFocusTrapTest.js`, and `a11yTest.js` |
| S5 | `defaultAdminDrupalMessageTest.js`, `defaultAdminPrimaryTabsTest.js`, `a11yTestAdmin.js`, `a11yTestDefault.js` |
| S6 | `drupalHtmlBuilderTest.js` |

## Run the Playwright suite

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
teardown cleans up this run's registered sites. Global setup also recovers
records in the current database scope from runs inactive for at least 24
hours. Both use the installer's container-side lock. The repository root holds
`playwright-report/` and `playwright_output/`, which contains the JUnit file
and retained traces.
