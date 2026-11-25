# Drupal core without jQuery: review guide

This repository holds a reference build of Drupal core with jQuery and jQuery UI removed. It is an experiment, not
an upstream contribution. It removes them completely, with no backward-compatibility layer, and uses htmx only where
it does the same job with less code. The aim is to have something concrete to compare against whatever core
eventually ships.

- **`upstream-core`** is upstream Drupal core (`main` at `92e5c3a3047`, 2026-11-18), plus this guide.
- **`jquery-hardcut`** holds the change: 532 commits, 714 files, +27,175 / −44,453 lines.
- **The pull request** shows the whole diff and every commit. Read it there.

The work was implemented and reviewed with AI coding agents under my direction, in small steps. Each step was
accepted only after independent reviews and a full browser test run.

## How the change is organized

The commits follow these steps. Every step keeps the site working.

| Step | What it did |
| --- | --- |
| Groundwork | Ratchet tests that track every jQuery edge, plus an event bridge used only during the migration. |
| 1 | Removed the jQuery bridges from core JavaScript (`ajax.js`, `states.js`, `form.js`, `tabledrag.js` and others). Removed Nightwatch. |
| 2 | Dialogs moved to native `<dialog>`: modal, off-canvas and top trays, and the Ajax dialog commands. |
| 3 | Autocomplete became a native combobox. jQuery UI was deleted. |
| M1 | Views UI and Views. |
| M2 | Editor and CKEditor 5 integration. Only Drupal's integration layer changed, not CKEditor itself. |
| M3 | Remaining modules: Block, Contextual, Field UI, File, Layout Builder, Media, Media Library, and the Default Admin theme scripts. |
| Z | Deleted `core/jquery`, the vendored jQuery and jQuery UI files, `jquery.no-conflict.js` and the build inputs. |
| Canary | `test_jquery_free`, a diagnostic module that flags contrib and custom code still expecting jQuery. |

## Where to start reading

Roughly in order of impact:

1. **Shared request and form code** in `core/misc`: `ajax.js` (request lifecycle, command queue, stale-response
   handling, focus), `form.js` and the new `form-events.js`, `states.js`, `tabledrag.js`, `autocomplete.js`.
2. **Dialogs and trays** in `core/misc/dialog`: `dialog.js`, `dialog.ajax.js`, `off-canvas/`, and the htmx tray path
   used by Workspaces.
3. **Module integrations:**
   - `core/modules/views_ui`
   - `core/modules/ckeditor5` and `core/modules/editor`
   - `core/modules/media_library` and `core/modules/file`
   - `core/modules/layout_builder`, `contextual`, `block`, `field_ui`, `navigation`
   - `core/themes/default_admin`
4. **PHP changes** are small and listed in the step commits. For example, `FormBuilder` keeps a stable root
   `data-drupal-selector` so Ajax focus survives a rebuild.
5. **Deletions:** `core/assets/vendor/jquery*` (about 18,000 lines), `core/misc/jquery.form.js`,
   `core/misc/position.js`.

The Ajax wire names (`html`, `replaceWith`, `append`) are unchanged. The vendored Sortable bundle is left untouched.

## What keeps jQuery out

These checks are permanent:

- **ESLint** forbids the `jQuery` and `$` globals in core JavaScript (`core/eslint.config.mjs`). Its fixture test is
  `core/scripts/js/jquery-ratchet.test.mjs`.
- **`JqueryFreeLibrariesTest`** (Kernel): no core library depends on, or declares, a jQuery library or asset path.
  It also checks Twig `attach_library()` calls and theme `libraries:` entries.
- **`JqueryFreePagesTest`** (browser): representative core pages load no jQuery.

## The canary: `core/modules/test_jquery_free`

After a hard cut, contrib and custom code breaks. The canary shows where.

- **Reports › jQuery readiness** scans the enabled modules and themes for library dependencies on removed jQuery
  libraries, and for script references such as `jQuery`, `$(` and `(function ($)`. A status report entry summarizes
  the findings.
- **In the browser**, it warns once for each file and line that reads `window.jQuery` or `window.$`.
- **Drush:** `drush test-jquery-free:report [--rescan] [--format=json]`.
- Its README lists its limits. The static scan is best-effort.

## Test results

- **Final full sweep:** every FunctionalJavascript class in core, 237 in total. 235 pass. Two fail, and both fail the
  same way on unmodified core in the same environment:
  - `MaximumFileSizeExceededUploadTest`: the upload limit in the test container.
  - `BrowserWithJavascriptTest`: the headless window size.
- **After the sweep:** recording the demo found two regressions that no test covered:
  - On Block layout, changing a block's region did not move its row.
  - The Workspaces top tray was clipped to 300px wide.

  Both are fixed and now tested. The 16 related browser classes pass.
- Unit, Kernel, PHPStan, PHPCS, ESLint, Stylelint and the Node tests pass.

## Known open items

None of these blocks a comparison.

- **Workspaces tray:** a newer htmx request started while the tray is opening can leave the first tray without its
  behaviors, if the newer request fails.
- **Lint:** the jQuery rule does not catch `window[0]['$']()`. Truly dynamic global lookups are out of scope.
- **Upload test:** recovery after a cancelled mixed upload is tested in a different widget than the one cancelled.
- **Dialogs:** reusing an already-open dialog closes and reopens it instead of only re-running the open steps.
- **Block layout tests:**
  - The test for the region select can pass even without the fix, because the original failure was a timing race.
  - The right-to-left tray offset is untested.

Several issues also exist in unmodified core and are left as they are, for example:

- Default Admin's sticky table header throws when an Ajax preview adds a column.
- Field UI and taxonomy assign their row callbacks on the shared tabledrag prototype.

## Running it

It is ordinary Drupal core. Check out `jquery-hardcut`, run `composer install`, and install any profile. The Umami
demo (`demo_umami`) shows the most features.
