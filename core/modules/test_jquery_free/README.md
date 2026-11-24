# jQuery readiness canary

Enable the canary and open **Reports > jQuery readiness**, or run
`drush test-jquery-free:report` with Drush 13.7 or later. Add `--format=json`
for structured output. The status report reads cached results. Open the
report to scan on a cache miss. Choose **Rescan** or pass `--rescan` to refresh
the results. Each scan logs one warning per affected extension.

The library scan follows dependency chains for each installed theme. A blank
Theme column means the finding applies across themes. The scan recognizes
historical core library IDs even after removal. Theme overrides, extensions,
and global attachments affect the graph.

The scanner switches the active theme with Drupal's theme manager and restores
the previous theme in a finally block. It records newly added edges after each
module or theme alter callback and credits theme declarations to their author.
A dependency already present before a callback keeps its existing attribution.
The scan only observes hooks under the current request and configuration.
One discovery failure can hide other libraries from the same extension.

The script scan checks local JavaScript for `jQuery`, `$(`, `$.`, and signatures
such as `(function ($)`. It includes comments and strings, can flag unrelated uses
of `$`, and can miss aliases or expressions split across lines. It skips hidden
directories, tests, node_modules, nested extensions, and remote assets.

The browser canary warns once per caller URL and line, where the browser exposes
them. A missing global reads as `undefined`, including after an assignment of
`undefined`. The accessor must exist to intercept the read, so
`'jQuery' in window` returns true while armed. The accessor is non-enumerable.
Aggregated scripts share a URL. If jQuery is already present, the canary warns
once and preserves the existing globals. A later defined value replaces the
accessor for that global.
