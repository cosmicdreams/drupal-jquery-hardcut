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

async function lint(source) {
  const [result] = await eslint.lintText(source, { filePath: 'fixture.js' });
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
  ]) {
    assert.ok((await lint(source)).includes('drupal-jquery/no-reference'), source);
  }
});

test('allows local properties and bindings with the same names', async () => {
  assert.deepEqual(
    await lint('const browser = { $() {} };\nbrowser.$();\n'),
    [],
  );
});
