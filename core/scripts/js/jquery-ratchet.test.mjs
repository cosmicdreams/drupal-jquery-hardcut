/**
 * @file
 * Checks source and shipped JavaScript with Drupal's jQuery lint rule.
 */

import assert from 'node:assert/strict';
import { execFileSync } from 'node:child_process';
import { createHash } from 'node:crypto';
import { mkdtempSync, readdirSync, readFileSync, rmSync } from 'node:fs';
import { tmpdir } from 'node:os';
import { join } from 'node:path';
import { test } from 'node:test';
import { fileURLToPath } from 'node:url';
import { Linter } from 'eslint';
import { jqueryPlugin, jqueryRules } from './jquery-ratchet.mjs';
const allowList = JSON.parse(
  readFileSync(
    new URL(
      '../../tests/Drupal/KernelTests/Core/Asset/fixtures/jquery-source-allow-list.json',
      import.meta.url,
    ),
    'utf8',
  ),
);

const core = fileURLToPath(new URL('../../', import.meta.url));
const linter = new Linter();

function lint(source, sourceType = 'module') {
  return linter.verify(
    source,
    {
      languageOptions: { sourceType },
      plugins: { 'drupal-jquery': jqueryPlugin },
      rules: jqueryRules,
    },
    { allowInlineConfig: false },
  );
}

function lintFile(path, bytes) {
  // The optimizer tests include a big-endian UTF-16 fixture.
  const encoding =
    bytes[0] === 0xfe && bytes[1] === 0xff ? 'utf-16be' : 'utf-8';
  const source = new TextDecoder(encoding).decode(bytes);
  const messages = lint(source, path.endsWith('.cjs') ? 'commonjs' : 'module');
  // Some browser scripts can only be parsed in script mode.
  return path.endsWith('.js') && messages.some(({ fatal }) => fatal)
    ? lint(source, 'script')
    : messages;
}

function fingerprint(bytes) {
  return createHash('sha256').update(bytes).digest('hex');
}

test('recognizes jQuery reference acquisition', () => {
  const examples = [
    "jQuery('.item');",
    "const jq = $; jq('.item');",
    "import jq from 'jquery'; jq('.item');",
    "export { default as jq } from 'jquery';",
    "import('jquery-ui/ui/widget');",
    "const jq = require('jquery/dist/jquery.js');",
    "define(['jquery'], (jq) => jq('.item'));",
    "const jq = globalThis['jQuery']; jq('.item');",
    "window?.['$']('.item');",
    'window[`jQuery`];',
    "const browser = window; const { ['jQuery']: jq } = browser; jq('.item');",
    'let jq; ({ $: jq } = self);',
    'function lookup({ jQuery: jq } = window) { return jq; }',
    'const browser = globalThis; const alias = browser; alias.jQuery;',
  ];
  for (const source of examples) {
    assert.deepEqual(
      lint(source).map(({ ruleId }) => ruleId),
      ['drupal-jquery/no-reference'],
      source,
    );
  }
});

test('accepts ordinary local names and properties', () => {
  const source = `
    const $ = (selector) => document.querySelector(selector);
    $('.item')?.setAttribute('hidden', '');
    const window = { jQuery: { attr() {} } };
    const { jQuery: options } = window;
    options.attr();
    function configure(require) { require('jquery'); }
    (($) => $('.item').attr())(() => ({ attr() {} }));
  `;
  assert.deepEqual(lint(source), []);
});

test('checks a wrapped legacy asset and neutral vendor minification', () => {
  const jqueryUi = 'assets/vendor/jquery.ui/ui/widget-min.js';
  assert.ok(
    lintFile(jqueryUi, readFileSync(join(core, jqueryUi))).some(
      ({ ruleId }) => ruleId === 'drupal-jquery/no-reference',
    ),
  );
  for (const path of [
    'assets/vendor/ckeditor5/ckeditor5.umd.js',
    'assets/vendor/floating-ui/floating-ui.dom.umd.min.js',
    'assets/vendor/htmx/htmx.min.js',
  ]) {
    assert.deepEqual(lintFile(path, readFileSync(join(core, path))), [], path);
  }
});

const files = [
  ...new Set(
    execFileSync('git', ['ls-files', '-z', '--', '*.js', '*.mjs', '*.cjs'], {
      cwd: core,
      encoding: 'utf8',
    })
      .split('\0')
      .filter(Boolean),
  ),
];

test('checks tracked JavaScript against the exact source and distribution lists', () => {
  const actual = new Set();
  const pinned = new Set(Object.values(allowList.distribution));
  const remaining = new Set([
    ...Object.keys(allowList.distribution),
    ...Object.keys(allowList.invalidFixtures),
  ]);
  const unexpected = [];
  for (const path of files) {
    const bytes = readFileSync(join(core, path));
    const digest = fingerprint(bytes);
    const distribution = allowList.distribution[path];
    const invalidFixture = allowList.invalidFixtures[path];
    if (distribution || invalidFixture) {
      assert.equal(digest, distribution ?? invalidFixture, path);
      remaining.delete(path);
      continue;
    }
    assert.ok(
      !pinned.has(digest),
      `${path} copies a legacy distribution asset.`,
    );
    const messages = lintFile(path, bytes);
    if (messages.length) {
      actual.add(path);
      if (
        allowList.sources[path]?.references !== messages.length ||
        messages.some(({ fatal }) => fatal)
      ) {
        unexpected.push(
          ...messages.map(
            ({ line, column, message }) =>
              `${path}:${line}:${column} ${message}`,
          ),
        );
      }
    }
  }
  assert.equal(
    unexpected.join('\n'),
    '',
    'Unexpected jQuery references or parse errors.',
  );
  assert.deepEqual([...remaining], [], 'Remove missing pinned exceptions.');
  assert.deepEqual(
    [...actual].sort(),
    Object.keys(allowList.sources).sort(),
    'Remove unused source exceptions.',
  );
});

test('rebuilds the tracked CKEditor 5 plugins from their webpack entries', () => {
  const output = mkdtempSync(join(tmpdir(), 'drupal-jquery-ratchet-'));
  const build = 'modules/ckeditor5/js/build';
  try {
    execFileSync(
      process.execPath,
      [
        'node_modules/webpack/bin/webpack.js',
        '--config',
        'modules/ckeditor5/webpack.config.js',
        '--output-path',
        output,
      ],
      { cwd: core },
    );
    const bundles = files.filter((path) => path.startsWith(`${build}/`)).sort();
    const rebuilt = readdirSync(output)
      .map((path) => `${build}/${path}`)
      .sort();
    assert.deepEqual(rebuilt, bundles);
    for (const path of bundles) {
      const filename = path.slice(build.length + 1);
      assert.equal(
        fingerprint(readFileSync(join(core, path))),
        fingerprint(readFileSync(join(output, filename))),
        `${path} differs from its webpack build.`,
      );
    }
  } finally {
    rmSync(output, { recursive: true, force: true });
  }
});
