import {
  expect,
  test,
} from '../../../../../tests/Drupal/Playwright/fixtures/base.fixture';

/**
 * Ports the Drupal HTML builder unit tests from drupalHtmlBuilderTest.js.
 *
 * Nightwatch did not support ES modules, so the original evaluated the class
 * directly and supplied a document through JSDOM. Playwright imports the
 * module in Chromium and uses its native document and nodes.
 *
 * @see https://www.drupal.org/project/drupal/issues/3247647
 * @see https://www.drupal.org/project/drupal/issues/3231364
 * @see https://www.drupal.org/project/drupal/issues/3253148
 * @see https://www.drupal.org/project/drupal/issues/3278415
 * @see https://www.drupal.org/project/drupal/issues/3342874
 * @see https://www.drupal.org/project/drupal/issues/3364884
 * @see https://www.drupal.org/project/drupal/issues/3473014
 * @see https://www.drupal.org/project/drupal/issues/3483908
 */
test.describe('Drupal HTML builder', { tag: '@ckeditor5' }, () => {
  // These unit cases only need the browser DOM. Use Playwright's defaults.
  test.use({ baseURL: undefined, storageState: undefined });

  test('NW-drupalHtmlBuilderTest-empty-fragment', async ({
    drupalHtmlBuilder,
  }) => {
    await test.step('Serialize an empty document fragment', async () => {
      const html = await drupalHtmlBuilder.evaluate((build) =>
        build(document.createDocumentFragment()),
      );
      expect(html).toBe('');
    });
  });

  test('NW-drupalHtmlBuilderTest-single-text-node', async ({
    drupalHtmlBuilder,
  }) => {
    await test.step('Serialize a single text node', async () => {
      const html = await drupalHtmlBuilder.evaluate((build) => {
        const fragment = document.createDocumentFragment();
        fragment.appendChild(document.createTextNode('foo bar'));
        return build(fragment);
      });
      expect(html).toBe('foo bar');
    });
  });

  test('NW-drupalHtmlBuilderTest-paragraph', async ({ drupalHtmlBuilder }) => {
    await test.step('Serialize a paragraph and its text', async () => {
      const html = await drupalHtmlBuilder.evaluate((build) => {
        const fragment = document.createDocumentFragment();
        const paragraph = document.createElement('p');
        paragraph.textContent = 'foo bar';
        fragment.appendChild(paragraph);
        return build(fragment);
      });
      expect(html).toBe('<p>foo bar</p>');
    });
  });

  test('NW-drupalHtmlBuilderTest-multiple-children', async ({
    drupalHtmlBuilder,
  }) => {
    await test.step('Serialize text and elements in fragment order', async () => {
      const html = await drupalHtmlBuilder.evaluate((build) => {
        const fragment = document.createDocumentFragment();
        const text = document.createTextNode('foo bar');
        const paragraph = document.createElement('p');
        const div = document.createElement('div');

        paragraph.textContent = 'foo';
        div.textContent = 'bar';
        fragment.append(text, paragraph, div);
        return build(fragment);
      });
      expect(html).toBe('foo bar<p>foo</p><div>bar</div>');
    });
  });

  test('NW-drupalHtmlBuilderTest-script-and-style', async ({
    drupalHtmlBuilder,
  }) => {
    await test.step('Preserve raw script and style text', async () => {
      const html = await drupalHtmlBuilder.evaluate((build) => {
        const fragment = document.createDocumentFragment();
        const script = document.createElement('script');
        script.textContent = `let x = 10;
let y = 5;
if (y < x) {
console.log('is smaller')
}`;
        const style = document.createElement('style');
        style.setAttribute('type', 'text/css');
        style.appendChild(
          document.createTextNode(':root .sections > h2 { background: red}'),
        );
        fragment.append(style, document.createTextNode('\n'), script);
        return build(fragment);
      });
      expect(html).toBe(
        `<style type="text/css">:root .sections > h2 { background: red}</style>
<script>let x = 10;
let y = 5;
if (y < x) {
console.log('is smaller')
}</script>`,
      );
    });
  });

  test('NW-drupalHtmlBuilderTest-comment', async ({ drupalHtmlBuilder }) => {
    await test.step('Serialize a comment after an element', async () => {
      const html = await drupalHtmlBuilder.evaluate((build) => {
        const fragment = document.createDocumentFragment();
        const div = document.createElement('div');
        const comment = document.createComment('bar');
        div.textContent = 'bar';
        fragment.append(div, comment);
        return build(fragment);
      });
      expect(html).toBe('<div>bar</div><!--bar-->');
    });
  });

  test('NW-drupalHtmlBuilderTest-attributes', async ({ drupalHtmlBuilder }) => {
    await test.step('Serialize attributes in insertion order', async () => {
      const html = await drupalHtmlBuilder.evaluate((build) => {
        const fragment = document.createDocumentFragment();
        const div = document.createElement('div');
        div.setAttribute('id', 'foo');
        div.classList.add('bar');
        div.textContent = 'baz';
        fragment.appendChild(div);
        return build(fragment);
      });
      expect(html).toBe('<div id="foo" class="bar">baz</div>');
    });
  });

  test('NW-drupalHtmlBuilderTest-void-element', async ({
    drupalHtmlBuilder,
  }) => {
    await test.step('Serialize a horizontal rule without an end tag', async () => {
      const html = await drupalHtmlBuilder.evaluate((build) => {
        const fragment = document.createDocumentFragment();
        fragment.appendChild(document.createElement('hr'));
        return build(fragment);
      });
      expect(html).toBe('<hr>');
    });
  });

  test('NW-drupalHtmlBuilderTest-attribute-escaping', async ({
    drupalHtmlBuilder,
  }) => {
    await test.step('Escape ampersands and angle brackets in attribute values', async () => {
      const html = await drupalHtmlBuilder.evaluate((build) => {
        const fragment = document.createDocumentFragment();
        const div = document.createElement('div');
        div.setAttribute('data-caption', 'Kittens & llamas are <em>cute</em>');
        div.textContent = 'foo';
        fragment.appendChild(div);
        return build(fragment);
      });
      expect(html).toBe(
        '<div data-caption="Kittens &amp; llamas are &lt;em&gt;cute&lt;/em&gt;">foo</div>',
      );
    });
  });
});
