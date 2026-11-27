# Playwright coverage gaps

| Area | Uncovered behavior | Why | Unlock signal |
|---|---|---|---|
| Nightwatch corpus beyond S2 | HTMX, navigation, administration, accessibility and editor tests | S0 established the harness. S1 covers core JavaScript, and S2 covers the forms corpus with Playwright and existing PHPUnit browser tests. | Complete S3 through S6 and account for each original assertion in CORPUS.md. |
| `drupalCreateRole.js:44` | The `table.permissions` selector identifies a visible permissions table | `UserPermissionsTest::testPermissionTableHtml()` checks the cell count of a module header row. | Assert that `table.permissions` is visible on `/admin/people/permissions`. |
| `drupalCreateUser.js:35,40` | Password field is visible on the administrative create-user form with notification unchecked | `UserCreateTest::testUserAdd()` submits the field through a non-browser client. `PasswordConfirmWidgetTest::testPasswordConfirmWidgetJsComponents()` exercises the widget on the account edit form. | Add a browser visibility assertion on `/admin/people/create` with notification unchecked. |
| `drupalLogout.js:24` | The browser removes the session cookie after logout confirmation | `UserLogoutTest::testLogout()` checks server-side session invalidation. `SessionTest::testEmptyAnonymousSession()` checks cookie removal on empty anonymous sessions. | Assert the browser cookie is absent after submitting `/user/logout/confirm`. |

S1 introduces no uncovered assertions. The two deprecation warnings use Playwright console events.

S2 introduces no uncovered assertions. The corpus identifies the existing browser test that covers the States visibility and spinner assertions.
