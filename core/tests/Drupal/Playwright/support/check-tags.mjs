#!/usr/bin/env node

import { globSync, readFileSync } from 'node:fs';
import { resolve } from 'node:path';

const suite = resolve(import.meta.dirname, '..');
const core = resolve(suite, '../../..');
const specs = [
  'tests/Drupal/Playwright/specs/**/*.spec.ts',
  'modules/*/tests/src/Playwright/**/*.spec.ts',
  'themes/*/tests/src/Playwright/**/*.spec.ts',
];
const tagPattern = /['"](@[^'"]+)['"]/g;
const definitions = readFileSync(resolve(suite, 'TAGS.md'), 'utf8');
const defined = new Set(
  Array.from(
    definitions.matchAll(/^\|\s*`(@[a-z][a-z0-9-]*)`/gm),
    ([, tag]) => tag,
  ),
);
const applied = new Map();

for (const file of globSync(specs, { cwd: core })) {
  const source = readFileSync(resolve(core, file), 'utf8');
  // Read the tags from the test options, excluding documentation comments.
  for (const option of source.match(
    /\btag:\s*(?:\[[^\]]*\]|'[^']*'|"[^"]*")/g,
  ) ?? []) {
    for (const [, tag] of option.matchAll(tagPattern)) {
      const files = applied.get(tag) ?? new Set();
      files.add(file);
      applied.set(tag, files);
    }
  }
}

const unused = [...defined].filter((tag) => !applied.has(tag));
const undocumented = [...applied].filter(([tag]) => !defined.has(tag));

for (const tag of unused) {
  console.error(`Defined in TAGS.md but unused in specs: ${tag}`);
}
for (const [tag, files] of undocumented) {
  console.error(`Missing from TAGS.md: ${tag} (${[...files].join(', ')})`);
}

if (unused.length || undocumented.length) {
  process.exitCode = 1;
} else {
  console.log(`tags ok — ${defined.size} defined, all applied`);
}
