# jQuery source and shipped JavaScript

From `core/`, run `node --test scripts/js/jquery-ratchet.test.mjs` alongside
the regular ESLint and Kernel asset tests. The Node test scans tracked
JavaScript with the same rule, including files skipped by style linting.
The check disallows inline lint suppressions.

The source list records reference counts and owners at `92e5c3a3047`. Reduce
the counts as each slice converts its files and remove rows once clean. The
Kernel asset tests check where those legacy files are attached, along with
literal PHP attachments and stylesheet references.

- CKEditor 5 builds come from `js/ckeditor5_plugins/<plugin>/src/index.js`.
  The test rebuilds with the existing webpack configuration in a temporary
  directory and compares every tracked output byte for byte. C owns entity
  link suggestions. M owns the other four outputs.
- Default Admin migration scripts are tracked source and shipped assets. The
  theme declares them in `default_admin.libraries.yml` or substitutes them in
  `default_admin.info.yml`. T owns the two legacy table scripts. The vendored
  Scroll Sync script has a clean syntax scan.
- Z owns the pinned jQuery and jQuery UI distribution files. The listed
  SHA-256 fingerprints cover source and minified JavaScript. The scan flags
  byte-identical copies outside those paths. Sortable also has a Z-owned
  source exception. Its upstream build checks `window.jQuery` when cloning.
- H owns the exact fingerprint exception for the asset optimizer's
  deliberately invalid JavaScript fixture.

The source gate checks statically identifiable references. Computed names
and module paths, values passed across files, and vendor code transformed
beyond the pinned fingerprints can require build or runtime evidence.
