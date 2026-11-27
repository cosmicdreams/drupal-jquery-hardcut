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
| `drupalCreateUser.js:39–40` | The password input is visible | Harness setup, not ported | See [coverage gaps](COVERAGE-GAPS.md) |
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

<!-- cspell:ignore Äwesome dnoooooxouuuuythss dnoooooxouuuuuthss ÐÑÒÓÔÕÖ ØÙÚÛÜÝ drupalhtmlbuilder -->

Core origins are relative to `core/tests/Drupal/Nightwatch/Tests/`.
The Default Admin original is in `core/themes/default_admin/tests/src/Nightwatch/`.
Each Playwright case has an isolated site. The installer provisions `form_test`
and its dependencies for all three cases. The autocomplete setup selects Default
Admin as the default theme and signs in a disposable account with
`access autocomplete test` through the shared fixture.

| Origin | Assertion | Disposition | Coverage |
|---|---|---|---|
| `statesTest.js:12,19` | The full States form page body is visible | Port faithfully. Both visits used the same fixture and initial state. | `NW-statesTest-form-deprecations` |
| `statesTest.js:13` | The textfield controlled by an OR condition starts hidden | Port faithfully on the original form. | `NW-statesTest-form-deprecations` |
| `statesTest.js:14` | The full States form logs no deprecation warnings | Adapt. Subscribe to the browser console for Drupal's `[Deprecation]` warnings. | `NW-statesTest-form-deprecations` |
| `statesTest.js:20,32` | The dependent item starts hidden and appears after the number spinner changes | Adapt. Press Arrow Up and Tab and suppress the Arrow Up key release so the dependent item must respond to the browser’s native change event. | `NW-statesTest-form-deprecations` |
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

## S3 htmx

<!-- cSpell:ignore beforebegin afterbegin beforeend afterend -->

The source is `core/tests/Drupal/Nightwatch/Tests/htmx/htmxTest.js` at
`949d5a22038^`. The file history traces #3521173, #3522597, #3532159,
#3555916, and #3560659. All assertions below are faithful ports.

| Original case | Body, trigger, and direct-route assertions | Asset assertions (before / after) | Swap result assertions | Playwright spec |
|---|---|---|---|---|
| Asset Load | 32–33, 40, 43 | 41–42 / 47–48 | 45–46 | `NW-htmxTest-load-assets` |
| Asset Load with wrapper format | 58, 61 | 59–60 / 65–66 | 63–64 | `NW-htmxTest-wrapper-assets` |
| innerHTML | 75, 78 | 76–77 / 82–83 | 80–81 | `NW-htmxTest-swap-strategies` / `inner-html` |
| outerHTML | 88, 91 | 89–90 / 95–96 | 93–94 | same / `outer-html` |
| textContent | 101, 104 | 102–103 / — | 106–112 | same / `text-content` |
| beforebegin | 117, 120 | 118–119 / 124–125 | 122–123 | same / `beforebegin` |
| afterbegin | 130, 133 | 131–132 / 137–138 | 135–136 | same / `afterbegin` |
| beforeend | 143, 146 | 144–145 / 150–151 | 148–149 | same / `beforeend` |
| afterend | 156, 159 | 157–158 / 163–164 | 161–162 | same / `afterend` |
| innerMorph | 169, 172 | 170–171 / 176–177 | 174–175 | same / `inner-morph` |
| outerMorph | 182, 185 | 183–184 / 189–190 | 187–188 | same / `outer-morph` |
| outerSync | 195, 198 | 196–197 / 202–203 | 200–201 | same / `outer-sync` |
| delete | 208–211 | — | 213–217 | `NW-htmxTest-delete-detach` |
| Ajax Load HTMX Element | 224–225, 232, 235, 238 | 233–234 / 243–244 | 241–242 | `NW-htmxTest-ajax-inserted-trigger` |
| Boosted a tag | 252–253, 259, 262 | 260–261 / 266–267 | 264–265 | `NW-htmxTest-boosted-body` |

The original already exercised htmx 4 after `2aa5aede43f`. The current
controller still maps the original route segments to these swap values.

| Original route suffix | Current htmx swap |
|---|---|
| `inner-html/swap` | `innerHTML` |
| `outer-html/swap` | `outerHTML` |
| `text-content/swap` | `textContent` |
| `beforebegin/swap` | `beforebegin` |
| `afterbegin/swap` | `afterbegin` |
| `beforeend/swap` | `beforeend` |
| `afterend/swap` | `afterend` |
| `inner-morph/swap` | `innerMorph` |
| `outer-morph/swap` | `outerMorph` |
| `outer-sync/swap` | `outerSync` |
| `delete` | `delete` |

Each test installs Minimal and `test_htmx` through `siteOptions.modules`.
This replaces `HtmxAssetLoadTestSetup.php`, whose only action was to install
`test_htmx`. The ten swap variants share a spec file because they use the same fixture
and sequence. Each variant runs as a separate test on a fresh site. The Ajax case waits for Drupal's Ajax behavior binding and
for the inserted button in place of the two fixed pauses.

The named swap tests verify the strategy sent by the fixture. The default
case verifies the absence of an explicit strategy. The boosted case requires
a real htmx request so a full-page fallback cannot pass the asset assertions.
All six named routes and all ten swap variants still exist. The historical
`reveal-merged-settings.js` reference is retained as written. The current
fixture loads `test_htmx/js/behavior.js` and `test_htmx/css/style.css`.

`HtmxDynamicFormTest::testWrapperFormat()` already checks the wrapper flag on
the same route. The Playwright case verifies the script, stylesheet, visible
response and behavior marker. `HtmxDynamicFormTest::testPartialDelete()`
deletes a different target on the form-builder route and has no detach
assertion. `HtmxRequestTest` checks render attributes, `HtmxRendererTest`
checks response markup, and `HtmxRendererCacheTest` checks response caching.
The `6f31c914380` vendor regression test covers guarded swap cancellation
and history. For the hard-cut workspace changes (`7f3688850a9`,
`2d250ebbb6f`), `NavigationWorkspacesDialogTest::testSharedTrayOpeningOrder()`
delays the competing Workspaces and Layout Builder requests and checks the
winning content in the same tray. `211a73d75ab` removes jQuery references.
S3 introduces no retirement, new `PW-` spec, or uncovered corpus assertion.

## S4 navigation

All four originals are under
`core/modules/navigation/tests/src/Nightwatch/Tests/` in the historical tree.
Their seven cases are in `core/modules/navigation/tests/src/Playwright/`.

| Original case | Playwright spec | Classification and assertion mapping |
|---|---|---|
| `expandCollapseTest.js:25–45`, wide | `NW-expandCollapseTest-sidebar-toggle` | Adapt. At 1220 × 800, submit the original cache-clear form with BigPipe enabled. Assert the document listener marker, absence of the expanded footer toggle, collapsed toggle state, and collapsed document state. After expanding, assert the expanded toggle and document state. After collapsing, repeat all three collapsed checks. Start with the persisted collapsed preference so a fresh authenticated context reproduces the original starting state. |
| `expandCollapseTest.js:48–80`, narrow | Same file, Escape case | Adapt. Recreate the viewport and collapsed preference carried over from the wide case. Load at 1220 × 800 with the saved preference set to false, verify the initialized collapsed state, then resize to 1000 × 800. Verify the Configuration trigger is hidden, becomes visible on opening, and is hidden after Escape. Assert the same mobile trigger loses focus on opening and regains it on Escape. The Configuration button has the accessible name “Extend Configuration”. |
| `navigationDisplaceTest.js:17–24` | `NW-navigationDisplaceTest-sidebar-offset` | Port faithfully. At 1220 × 800, assert `data-offset-left` on the actual sidebar displacement placeholder at `/admin/` with BigPipe enabled. |
| `navigationMobileFocusTrapTest.js:47–197`, close button | `NW-navigationMobileFocusTrapTest-sidebar-inert` | Adapt. On the 375 × 800 homepage, verify the body is visible and the burger reports closed, open, and closed in order. After opening, every original non-sidebar body child has both inert attributes and the population is nonempty. The sidebar and overlay remain exempt. The original conditional focus probe becomes a guaranteed attempt on the real skip link, with a successful baseline attempt while the sidebar is closed. Closing clears every tracking marker. |
| `navigationMobileFocusTrapTest.js:199–251`, overlay | Same file, overlay case | Adapt. Verify the body, open burger state, and active tracking markers. Click the exposed edge of the actual overlay with a pointer. Verify the closed burger state and that all tracking markers clear. |
| `navigationMobileFocusTrapTest.js:253–304`, resize | Same file, resize case | Port faithfully. Load at 375 × 800, resize to 1280 × 800, and verify the sidebar is visible and tracking markers are absent. Resize back, verify the burger is visible and reports open after clicking, and verify tracking markers are present. |
| `a11yTest.js:15–45`, account edit | `NW-a11yTest-navigation-sidebar` | Adapt. Scan `body` on `/user/1/edit` at 1220 × 800 and 1000 × 800 in the same session. At the narrow width, verify the Expand sidebar button is visible and click it. Verify the original Create button, named “Extend Create”, is visible before scanning. Both scans require zero violations with empty axe options. The original loaded the page at the CI runner's 800 × 600 headless window before resizing, so the sidebar initialized collapsed and stored that preference; the port seeds the same collapsed preference. |

The non-accessibility cases use `playwright_testing` and install Navigation
with its dependencies through `siteOptions.modules`. The wide, narrow, and
displacement cases also install BigPipe. Each case gets a fresh site. The
focus-trap cases wait for the same animation marker using a Playwright
assertion. They preserve the original outside-child filter, which excludes
the control bar even though the current implementation also makes it inert.

The accessibility profile is restored as `playwright_a11y_testing`.
`system.theme.yml` already names `default_admin` and `stark`, and all 19
install configuration files are unchanged. The profile omits the deleted
`nightwatch_theme_install_utility`, which the Navigation case never invoked.
`DrupalPage.scanAccessibility(context, options)` uses
`@axe-core/playwright` 4.10.2 and the historical axe-core 4.10.3. Its direct
axe run mode preserves the old frame scope. The options pass through so S5
can supply each original scan's tags and disabled rules.

`NavigationWorkspacesDialogTest::testConfirmationFromNarrowTray()` clicks
the same overlay, but checks the HTML state and tray geometry. It reads the
same displacement placeholder's geometry. Its assertions do not replace the
burger state, focus trap cleanup, or displacement attribute checks here.
`NavigationUserBlockTest::testNavigationUserBlock()` checks the rendered
username. `NavigationBlockUiTest::testNavigationBlockAdminUiPageNestedForm()`
checks layout editing. The sidebar behavior remains native on the hard-cut
branch. There are no jQuery-only retirements or new `PW-` cases.

## S5 Default Admin and core theme accessibility

The theme originals were under `core/themes/default_admin/tests/src/Nightwatch/`.
The accessibility originals were under `core/tests/Drupal/Nightwatch/Tests/`.
All four are available in `949d5a22038^`.

| Original case                                                                                                                          | Playwright spec                                      | Classification and assertion mapping                                                                                                                                                                                                                                                                                                                             |
| -------------------------------------------------------------------------------------------------------------------------------------- | ---------------------------------------------------- | ---------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| `defaultAdminDrupalMessageTest.js:36–62`                                                                                               | `NW-defaultAdminDrupalMessageTest-message-placement` | Adapt. On the original system-message route, require visible content and the default wrapper. Assert three immediate message children, one of each type, then click the three Add buttons and assert six children, two of each type. Default Admin uses `.messages__wrapper` for the original destination.                                                       |
| `defaultAdminDrupalMessageTest.js:64–100`                                                                                              | Same file, custom area                               | Port faithfully. Require visible content and the custom wrapper. Assert it starts empty and ends with three immediate children, one of each type, after the three Add clicks.                                                                                                                                                                                    |
| `defaultAdminPrimaryTabsTest.js:28–35`                                                                                                 | `NW-defaultAdminPrimaryTabsTest-responsive-tabs`     | Port faithfully. At 1600 × 800 on `/node/1`, require the primary tabs and the active and first inactive tab to be visible. The toggle exists and is hidden.                                                                                                                                                                                                      |
| `defaultAdminPrimaryTabsTest.js:36–62`                                                                                                 | Same file, mobile case                               | Port visible primary navigation and active tab, the first inactive tab hidden, visible toggle, and exact expanded values `false`, `true`, `false` at 699 × 800. The first inactive tab becomes visible after opening and is still attached but hidden after closing. The single toggle icon must stay visible in the initial, open and closed states. The twelve assertions targeting two distinct icons have a [coverage gap](COVERAGE-GAPS.md). |
| `a11yTestAdmin.js:18–27,75–79`, User Edit, Create Article, Create Page, Content Page, Structure Page, Add content type, Add vocabulary | `NW-a11yTestAdmin-admin-theme`                       | Port faithfully. Scan `body` with empty options on each of the seven routes as user 1. Preserve the destination query on both creation forms.                                                                                                                                                                                                                    |
| `a11yTestAdmin.js:29–50,75–79`, Manage text format, mobile                                                                             | Same file, mobile case                               | Port faithfully. Scan `body` on the restricted HTML format at 415 × 823 with the exact seven historical tags.                                                                                                                                                                                                                                                    |
| `a11yTestAdmin.js:52–62,75–79`, Structure \| Block                                                                                     | Same file, block case                                | Port faithfully. Scan `body` on the block layout at 415 × 823, the size it inherited from the preceding mobile case. Disable only `color-contrast`, `duplicate-id-active`, and `region`.                                                                                                                                                                                                               |
| `a11yTestDefault.js:22–41,48–51`, Homepage and Login                                                                                   | `NW-a11yTestDefault-default-theme`                   | Port faithfully. Scan the same two public pages anonymously with `region` disabled.                                                                                                                                                                                                                                                                              |

The messages fixture installs `js_message_test` into `playwright_testing`
and makes Default Admin the default theme. The tabs fixture restores the
Minimal profile setup and content. It authenticates as a separate account
with the historical `administer nodes` grant.

Core theme scans use fresh `playwright_a11y_testing` sites and require a
successful response on the requested route before running axe. Nightwatch
ended the browser session once per test file, in the global `afterEach`.
Seven admin scans and two public scans ran at the historical CI window:
the GitLab job passed `--headless` with no `--window-size`, so headless Chrome
used its default 800 × 600. The port scans those nine at 800 × 600.
The two admin scans at 415 × 823 preserve the explicit mobile resize and
the Block case's inherited viewport.

Override the installed theme with `DRUPAL_TEST_ADMIN_THEME` or
`DRUPAL_TEST_DEFAULT_THEME`.
For example, run `DRUPAL_TEST_DEFAULT_THEME=default_admin yarn test:playwright --grep @a11y-default`
with the test environment configured below. The restored
`playwright_theme_install_utility` is installed only on sites with a theme
override. The authenticated installation request returns a confirmation
without rendering through the newly installed theme on that request.

## S6 CKEditor 5 HTML fragment serialization

The original is
`core/modules/ckeditor5/tests/src/Nightwatch/Tests/drupalHtmlBuilderTest.js`
at `949d5a22038^`. It contains nine unit cases. Each row maps to one exact
string assertion in `NW-drupalHtmlBuilderTest-fragment-serialization.spec.ts`.

| Original assertion | Playwright test suffix | Expected output                                                                     |
| ------------------ | ---------------------- | ----------------------------------------------------------------------------------- |
| Line 32            | `empty-fragment`       | Empty string                                                                        |
| Line 42            | `single-text-node`     | `foo bar`                                                                           |
| Line 52            | `paragraph`            | `<p>foo bar</p>`                                                                    |
| Lines 71–74        | `multiple-children`    | `foo bar<p>foo</p><div>bar</div>`                                                   |
| Lines 97–105       | `script-and-style`     | The typed style tag, newline, and script tag with raw text and original line breaks |
| Line 119           | `comment`              | `<div>bar</div><!--bar-->`                                                          |
| Lines 132–135      | `attributes`           | `<div id="foo" class="bar">baz</div>`                                               |
| Line 146           | `void-element`         | `<hr>`                                                                              |
| Lines 158–161      | `attribute-escaping`   | `<div data-caption="Kittens &amp; llamas are &lt;em&gt;cute&lt;/em&gt;">foo</div>`  |

The Playwright fixture imports the checked-in builder source (`drupalHtmlEngine/src/drupalhtmlbuilder.js`) into Chromium through a blob URL. Like the Nightwatch unit test, it bypasses Drupal library loading and the compiled bundle.
Each case constructs its own detached document fragment and builder, as in
the original. The original unit tests had no Drupal install, routes, login,
viewport changes, or state to carry forward. The `@ckeditor5` tag carries
forward the Nightwatch selection. There are no gaps or retirements.

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
