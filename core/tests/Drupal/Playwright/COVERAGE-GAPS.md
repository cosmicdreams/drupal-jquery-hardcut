# Playwright coverage gaps

| Area | Uncovered behavior | Why | Unlock signal |
|---|---|---|---|
| Nightwatch corpus beyond S0 | Remaining core JavaScript, forms, HTMX, navigation, administration, accessibility and editor tests | S0 establishes the test harness with login, install profile and installation language coverage. | Complete S1 through S6 and account for each original assertion in CORPUS.md. |
| `drupalCreateRole.js:44` | The permissions table is visible in the browser | `UserPermissionsTest::testPermissionTableHtml()` checks table markup through a non-browser client. | Add a browser visibility assertion on `/admin/people/permissions`. |
| `drupalCreateUser.js:35,40` | Password field is visible on the administrative create-user form with notification unchecked | `UserCreateTest::testUserAdd()` submits the field through a non-browser client. `PasswordConfirmWidgetTest::testPasswordConfirmWidgetJsComponents()` exercises the widget on the account edit form. | Add a browser visibility assertion on `/admin/people/create` with notification unchecked. |
| `drupalLogout.js:24` | The browser removes the session cookie after logout confirmation | `UserLogoutTest::testLogout()` checks server-side session invalidation. `SessionTest::testEmptyAnonymousSession()` checks cookie removal on empty anonymous sessions. | Assert the browser cookie is absent after submitting `/user/logout/confirm`. |
