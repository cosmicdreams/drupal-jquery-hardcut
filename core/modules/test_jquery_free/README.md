# jQuery readiness canary

Enable the jQuery readiness canary and open **Reports > jQuery readiness**
or run `drush test-jquery-free:report`.
The command requires Drush 13.7 or later. Add `--format=json` for structured
output. The status report counts affected extensions. Every report run logs one
warning per affected extension.

The report checks direct dependencies in Drupal's library discovery for the
current theme. It recognizes historical core jQuery and jQuery UI library
names even when the libraries have been removed. It also scans local
JavaScript files under enabled contributed and custom modules and installed
themes. It skips hidden directories, tests, node_modules and nested
extensions, which are considered separately when enabled. It does not fetch
remote assets.

Each source line is checked for the `jQuery` token, `$(`, `$.` and function
signatures such as `(function ($)`. The scan includes comments and strings,
can flag unrelated uses of `$`, and can miss aliases and expressions that span
multiple lines. Library findings have no source line.

The canary loads early and warns once per script that reads a missing
`window.jQuery` or `window.$`. Reads return `undefined`. It uses the caller
URL from the stack where available. With asset aggregation, several original
scripts may share one URL. If jQuery is already present at startup, the canary
warns once and leaves the globals alone. A script that defines a global later
can still assign it. The canary does not provide a replacement for jQuery.
