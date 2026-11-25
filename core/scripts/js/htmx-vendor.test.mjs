/**
 * @file
 * Verifies that vendor updates preserve the guarded htmx swap lifecycle.
 */

import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { test } from 'node:test';
import { Script } from 'node:vm';
import { JSDOM } from 'jsdom';
import patch from './assets/process/htmx.js';

for (const filename of ['htmx.js', 'htmx.min.js']) {
  test(`${filename} preserves the guarded swap lifecycle`, async (t) => {
    const file = { to: filename };
    const dist = readFileSync(
      new URL(`../../node_modules/htmx.org/dist/${filename}`, import.meta.url),
      'utf8',
    );
    const vendored = readFileSync(
      new URL(`../../assets/vendor/htmx/${filename}`, import.meta.url),
      'utf8',
    );
    assert.equal(patch({ file, contents: dist })[0].contents, vendored);
    assert.throws(
      () => patch({ file, contents: 'anchor changed' }),
      /Expected one htmx swap anchor/,
    );

    const dom = new JSDOM('<button></button><div>original</div>', {
      url: 'https://example.com/',
      runScripts: 'outside-only',
    });
    t.after(() => dom.window.close());
    const { document, XPathEvaluator } = dom.window;
    // This fixture has no inline htmx event handlers to scan for.
    XPathEvaluator.prototype.createExpression = () => ({
      evaluate: () => ({ iterateNext: () => null }),
    });
    new Script(vendored, { filename }).runInContext(dom.getInternalVMContext());
    const { htmx: client } = dom.window;
    const sourceElement = document.querySelector('button');
    const target = document.querySelector('div');
    let fresh = true;
    let insertCalls = 0;
    let cancelInsert = false;
    client.registerExtension('guard-test', {
      htmx_before_insert() {
        insertCalls++;
        return !cancelInsert;
      },
    });
    const swap = () =>
      client.swap({
        sourceElement,
        target,
        text: '<span>replacement</span>',
        swap: 'innerHTML settle:0',
        push: '/replacement',
        swapGuard: () => fresh,
      });

    sourceElement.addEventListener(
      'htmx:before:swap',
      () => { fresh = false; },
      { once: true },
    );
    await swap();
    assert.equal(insertCalls, 0);
    assert.equal(target.textContent, 'original');
    assert.equal(dom.window.location.pathname, '/');

    fresh = true;
    cancelInsert = true;
    await swap();
    assert.equal(insertCalls, 1);
    assert.equal(target.textContent, 'original');
    assert.equal(dom.window.location.pathname, '/');

    cancelInsert = false;
    document.addEventListener(
      'htmx:after:history:update',
      () => { fresh = false; },
      { once: true },
    );
    await swap();
    assert.equal(insertCalls, 2);
    assert.equal(target.textContent, 'original');
    assert.equal(dom.window.location.pathname, '/replacement');

    fresh = true;
    await swap();
    assert.equal(insertCalls, 3);
    assert.equal(target.innerHTML, '<span>replacement</span>');
  });
}
