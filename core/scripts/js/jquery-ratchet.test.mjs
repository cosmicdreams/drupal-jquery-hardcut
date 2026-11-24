/**
 * @file
 * Checks that the core ESLint config rejects jQuery references.
 */

import assert from 'node:assert/strict';
import { test } from 'node:test';
import { fileURLToPath } from 'node:url';
import { ESLint } from 'eslint';

const eslint = new ESLint({
  cwd: fileURLToPath(new URL('../../', import.meta.url)),
});

async function lint(source, filePath = 'fixture.js') {
  const [result] = await eslint.lintText(source, { filePath });
  return result.messages
    .filter(({ severity }) => severity === 2)
    .map(({ ruleId }) => ruleId);
}

test('rejects jQuery references acquired from browser globals and modules', async () => {
  for (const source of [
    'const browser = window;\nbrowser.$();\n',
    'const { $: select } = window;\nselect();\n',
    'const { jQuery } = window;\njQuery();\n',
    'require("jquery");\n',
    'import "jquery";\n',
    'globalThis.window.jQuery();\n',
    'window.self.$();\n',
    'document.defaultView.jQuery();\n',
    'const { window: browser } = globalThis;\nbrowser.jQuery();\n',
    'const { window: { jQuery: select } } = globalThis;\nselect();\n',
    'import "jquery-ui-dist";\n',
    'require("jquery-form");\n',
    'import("@example/jquery-extension/plugin");\n',
  ]) {
    assert.ok(
      (await lint(source)).includes('drupal-jquery/no-reference'),
      source,
    );
  }
});

test('allows local properties and bindings with the same names', async () => {
  assert.deepEqual(
    await lint('const browser = { $() {} };\nbrowser.$();\n'),
    [],
  );
});

test('checks jQuery in JavaScript excluded from other lint rules', async () => {
  for (const filePath of [
    'themes/default_admin/migration/js/fixture.js',
    'scripts/js/fixture.js',
    'modules/ckeditor5/js/build/fixture.js',
  ]) {
    assert.deepEqual(await lint('window.jQuery(  );', filePath), [
      'drupal-jquery/no-reference',
    ]);
    assert.deepEqual(await lint('$();', filePath), ['no-restricted-globals']);
  }
});
