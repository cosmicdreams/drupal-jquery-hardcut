#!/usr/bin/env node

import { execFileSync } from 'node:child_process';
import { readFileSync } from 'node:fs';
import { resolve } from 'node:path';
import { fileURLToPath } from 'node:url';

const suite = resolve(import.meta.dirname, '..');
const core = resolve(suite, '../../..');
const cli = fileURLToPath(import.meta.resolve('@playwright/test/cli'));
const definitions = readFileSync(resolve(suite, 'TAGS.md'), 'utf8');
const defined = new Set(
  Array.from(
    definitions.matchAll(/^\|\s*`(@[a-z][a-z0-9-]*)`/gm),
    ([, tag]) => tag,
  ),
);
const applied = new Map();

const { suites } = JSON.parse(
  execFileSync(process.execPath, [cli, 'test', '--list', '--reporter=json'], {
    cwd: core,
    encoding: 'utf8',
    maxBuffer: 10 * 1024 * 1024,
    stdio: ['ignore', 'pipe', 'inherit'],
  }),
);

function collectTags(suites) {
  for (const group of suites) {
    for (const { file, tags } of group.specs) {
      for (const name of tags) {
        const tag = `@${name}`;
        const files = applied.get(tag) ?? new Set();
        files.add(file);
        applied.set(tag, files);
      }
    }
    collectTags(group.suites ?? []);
  }
}

collectTags(suites);

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
