<?php

declare(strict_types=1);

namespace Drupal\FunctionalJavascriptTests;

use Drupal\Tests\field\Traits\EntityReferenceFieldCreationTrait;
use Drupal\Tests\node\Traits\ContentTypeCreationTrait;
use Drupal\Tests\node\Traits\NodeCreationTrait;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Tracks jQuery-family globals and assets on representative Standard pages.
 *
 * @todo Extend the core-only matrix to installer, update and batch, Umami,
 *   node display and edit, states, machine name, uploads, Media Library,
 *   CKEditor 5 link balloons, menu editing, table weights,
 *   other Field UI pages, contextual links, Layout
 *   Builder, tabbing, People, language, locale, modules, theme settings,
 *   nested modals, a generated Starterkit theme and a contrib subtheme.
 * @todo Test aggregated, cached and streaming responses with asset provenance.
 * @todo Add document-start instrumentation for Firefox and Safari.
 */
#[Group('javascript')]
#[RunTestsInSeparateProcesses]
class JqueryFreePagesTest extends WebDriverTestBase {

  use ContentTypeCreationTrait;
  use EntityReferenceFieldCreationTrait;
  use NodeCreationTrait;

  /**
   * Expected jQuery-family edges, keyed by page and edge.
   *
   * Values name the owner and removal slice, in that order. Default Admin's
   * styling still loads jQuery. Remove entries as each slice converts
   * its dependencies.
   */
  private const EXPECTED = [
    'block-layout' => [
      'global:jQuery' => ['Z', 'Z'],
      'library:jquery' => ['Z', 'Z'],
      'library:jquery-bootstrap' => ['B', 'Z'],
    ],
    'field-display' => [
      'global:jQuery' => ['Z', 'Z'],
      'library:jquery' => ['Z', 'Z'],
      'library:jquery-bootstrap' => ['B', 'Z'],
    ],
    'front' => [
      'global:jQuery' => ['Z', 'Z'],
      'library:jquery' => ['Z', 'Z'],
      'library:jquery-bootstrap' => ['B', 'Z'],
    ],
    'modal' => [
      'global:jQuery' => ['Z', 'Z'],
      'library:jquery' => ['Z', 'Z'],
      'library:jquery-bootstrap' => ['B', 'Z'],
    ],
    'node-add' => [
      'global:jQuery' => ['Z', 'Z'],
      'library:jquery' => ['Z', 'Z'],
      'library:jquery-bootstrap' => ['B', 'Z'],
    ],
    'off-canvas' => [
      'global:jQuery' => ['Z', 'Z'],
      'library:jquery' => ['Z', 'Z'],
      'library:jquery-bootstrap' => ['B', 'Z'],
    ],
    'permissions' => [
      'global:jQuery' => ['Z', 'Z'],
      'library:jquery' => ['Z', 'Z'],
      'library:jquery-bootstrap' => ['B', 'Z'],
    ],
    'vertical-tabs' => [
      'global:jQuery' => ['Z', 'Z'],
      'library:jquery' => ['Z', 'Z'],
      'library:jquery-bootstrap' => ['B', 'Z'],
    ],
  ];

  /**
   * {@inheritdoc}
   */
  protected $profile = 'standard';

  /**
   * {@inheritdoc}
   */
  protected $defaultTheme = 'default_admin';

  /**
   * {@inheritdoc}
   */
  protected $disableCssAnimations = FALSE;

  /**
   * {@inheritdoc}
   */
  protected static $modules = ['ajax_test', 'off_canvas_test'];

  /**
   * Checks jQuery-family assets after each page's representative interactions.
   */
  public function testCoreOnlyPages(): void {
    $this->config('system.performance')
      ->set('css.preprocess', FALSE)
      ->set('js.preprocess', FALSE)
      ->save();
    $this->installAssetObserver();
    $assert_session = $this->assertSession();
    $actual = [];

    $this->drupalLogin($this->createUser([], NULL, TRUE));
    $this->drupalGet('<front>');
    $assert_session->elementExists('css', 'main');
    $actual['front'] = $this->observedEdges();

    $this->createContentType(['type' => 'article', 'name' => 'Article']);
    $this->createNode(['type' => 'article', 'title' => 'Harness example article']);
    $this->createEntityReferenceField('node', 'article', 'field_reference', 'Related content', 'node', 'default', ['target_bundles' => ['article']]);
    \Drupal::service('entity_display.repository')->getFormDisplay('node', 'article')
      ->setComponent('field_reference', ['type' => 'entity_reference_autocomplete'])
      ->save();

    $this->drupalGet('node/add/article');
    $reference = $assert_session->elementExists('css', 'input[name="field_reference[0][target_id]"]');
    $reference->focus();
    $reference->setValue('Harness example');
    $suggestion = $assert_session->waitOnAutocomplete();
    $this->assertNotNull($suggestion);
    $suggestion->click();
    $this->assertStringContainsString('Harness example article', $reference->getValue());
    $actual['node-add'] = $this->observedEdges();

    // Default Admin uses an accordion on node forms. Use the content type
    // form to exercise its vertical tabs.
    $this->drupalGet('admin/structure/types/manage/article');
    $this->clickLink('Publishing options');
    $this->assertNotNull($assert_session->waitForElementVisible('css', '#edit-workflow'));
    $actual['vertical-tabs'] = $this->observedEdges();

    $this->drupalGet('admin/structure/block');
    $assert_session->elementExists('css', 'a.use-ajax[data-dialog-type="modal"]')->click();
    $assert_session->assertWaitOnAjaxRequest();
    $assert_session->elementExists('css', '#drupal-modal a.use-ajax[href*="/admin/structure/block/add/"]')->click();
    $assert_session->assertWaitOnAjaxRequest();
    $assert_session->elementTextContains('css', 'dialog[open]', 'Configure block');
    $actual['block-layout'] = $this->observedEdges();

    $this->drupalGet('admin/structure/types/manage/article/display/default');
    $handle = $assert_session->elementExists('css', 'tr:has([name="fields[body][region]"]) .tabledrag-handle');
    $handle->dragTo($assert_session->elementExists('css', '.region-hidden-title'));
    $assert_session->assertWaitOnAjaxRequest();
    $this->assertSame('hidden', $assert_session->fieldExists('fields[body][region]')->getValue());
    $actual['field-display'] = $this->observedEdges();

    $this->drupalGet('admin/people/permissions');
    $assert_session->elementExists('css', '#permissions tbody tr');
    $filter = $assert_session->elementExists('css', 'input.table-filter-text');
    $filter->setValue('no-permission-matches-this-value');
    $this->getSession()->getDriver()->keyUp($filter->getXpath(), ' ');
    $this->assertTrue($this->getSession()->wait(2000, 'Array.from(document.querySelectorAll("#permissions tbody tr")).every(row => row.offsetParent === null)'));
    $actual['permissions'] = $this->observedEdges();

    $this->drupalGet('ajax-test/dialog');
    $this->clickLink('Link 1 (modal)');
    $assert_session->assertWaitOnAjaxRequest();
    $assert_session->elementExists('css', 'dialog[open] #drupal-modal');
    $actual['modal'] = $this->observedEdges();

    $this->drupalGet('off-canvas-test-links');
    $this->clickLink('Open side panel 1');
    $assert_session->assertWaitOnAjaxRequest();
    $assert_session->elementTextContains('css', '#drupal-off-canvas', 'Thing 1 says hello');
    $actual['off-canvas'] = $this->observedEdges();

    $this->assertExpectedEdges($actual);
  }

  /**
   * Checks the Views editor and listing without theme-supplied libraries.
   */
  public function testViewsPagesWithoutJquery(): void {
    \Drupal::service('theme_installer')->install(['stark']);
    $this->config('system.theme')->set('default', 'stark')->set('admin', 'stark')->save();
    $this->config('views.settings')->set('ui.always_live_preview', FALSE)->save();
    $this->config('views.view.content')->set('display.default.display_options.use_ajax', TRUE)->save();
    $this->config('system.performance')->set('js.preprocess', FALSE)->save();
    $this->installAssetObserver();
    $this->drupalLogin($this->createUser([
      'administer views',
      'access content overview',
      'view the administration theme',
    ]));

    $this->drupalGet('admin/structure/views');
    $this->assertSession()->elementExists('css', 'input.views-filter-text');
    $this->assertSame([], $this->observedEdges(), 'Views listing');

    $this->drupalGet('admin/structure/views/view/content');
    $this->assertSession()->elementExists('css', '#views-add-field')->click();
    $this->assertSession()->assertWaitOnAjaxRequest();
    $this->assertSession()->elementExists('css', 'dialog[open] .filterable-option');
    $this->assertSame([], $this->observedEdges(), 'Views editor');

    $this->drupalGet('admin/content');
    $this->getSession()->getPage()->pressButton('Filter');
    $this->assertSession()->assertWaitOnAjaxRequest();
    $this->assertSame([], $this->observedEdges(), 'Ajax content view');
  }

  /**
   * Checks the editor runtime and configuration form without theme libraries.
   */
  public function testEditorPagesWithoutJquery(): void {
    \Drupal::service('theme_installer')->install(['stark']);
    $this->config('system.theme')->set('default', 'stark')->set('admin', 'stark')->save();
    $this->config('system.performance')->set('js.preprocess', FALSE)->save();
    $this->installAssetObserver();
    $this->drupalLogin($this->createUser([], NULL, TRUE));

    $this->createContentType(['type' => 'page', 'name' => 'Page']);
    $this->drupalGet('node/add/page');
    $this->getSession()->getPage()->selectFieldOption('body[0][format]', 'basic_html');
    $this->assertNotNull($this->assertSession()->waitForElementVisible('css', '.ck-editor'));
    $this->assertSame([], $this->observedEdges(), 'CKEditor 5 runtime');

    $this->drupalGet('admin/config/content/formats/manage/basic_html');
    $this->assertSession()->elementExists('css', '#ckeditor5-toolbar-app [role="listbox"]');
    $this->assertSame([], $this->observedEdges(), 'CKEditor 5 toolbar builder');
  }

  /**
   * Installs a Chromium document-start observer through WebDriver.
   */
  private function installAssetObserver(): void {
    $driver = $this->getSession()->getDriver();
    $this->assertInstanceOf(DrupalSelenium2Driver::class, $driver);
    $webdriver = $driver->getWebDriverSession();
    if (($webdriver->capabilities()['browserName'] ?? NULL) !== 'chrome') {
      $this->markTestSkipped('The document-start observer requires Chromium.');
    }
    $source = <<<'JS'
      (() => {
        const urls = new Set();
        const assets = 'script[src], link[rel~="stylesheet"][href]';
        function record(element) {
          if (element.nodeType !== Node.ELEMENT_NODE) {
            return;
          }
          if (element.matches(assets)) {
            urls.add(element.src || element.href);
          }
          element.querySelectorAll(assets).forEach((asset) => {
            urls.add(asset.src || asset.href);
          });
        }
        new MutationObserver((records) => {
          records.forEach((mutation) => {
            if (mutation.type === 'attributes') {
              record(mutation.target);
            } else {
              mutation.addedNodes.forEach(record);
            }
          });
        }).observe(document, {
          subtree: true, childList: true, attributes: true,
          attributeFilter: ['src', 'href', 'rel'],
        });
        new PerformanceObserver((list) => {
          list.getEntries().forEach(({ name }) => urls.add(name));
        }).observe({ type: 'resource', buffered: true });
        window.drupalJqueryObservedUrls = urls;
      })();
      JS;
    \Drupal::httpClient()->post($webdriver->getURL() . '/goog/cdp/execute', [
      'json' => [
        'cmd' => 'Page.addScriptToEvaluateOnNewDocument',
        'params' => ['source' => $source],
      ],
    ]);
  }

  /**
   * Finds jQuery-family globals and observed resource types.
   *
   * @return string[]
   *   The observed edge labels in sorted order.
   */
  private function observedEdges(): array {
    $edges = $this->getSession()->evaluateScript(<<<'JS'
      (() => {
        const urls = window.drupalJqueryObservedUrls;
        const edges = new Set();
        if (typeof window.jQuery !== 'undefined') {
          edges.add('global:jQuery');
        }
        if (window.$?.fn?.jquery) {
          edges.add('global:$');
        }
        performance.getEntriesByType('resource').forEach(({ name }) => urls.add(name));
        const paths = [
          ['library:jquery', /\/assets\/vendor\/jquery\//],
          ['library:jquery-bootstrap', /\/misc\/jquery\.no-conflict\.js$/],
        ];
        urls.forEach((url) => {
          const path = new URL(url).pathname;
          paths.forEach(([edge, pattern]) => {
            if (pattern.test(path)) {
              edges.add(edge);
            }
          });
        });
        return [...edges].sort();
      })()
      JS);
    $this->assertIsArray($edges, 'The document-start asset observer ran.');
    return $edges;
  }

  /**
   * Reports both new edges and entries that can be deleted.
   */
  private function assertExpectedEdges(array $actual): void {
    $messages = [];
    foreach ($actual as $page => $edges) {
      $expected = self::EXPECTED[$page] ?? [];
      foreach (array_diff($edges, array_keys($expected)) as $edge) {
        $messages[] = "$page has a new jQuery edge: $edge.";
      }
      foreach (array_diff(array_keys($expected), $edges) as $edge) {
        $messages[] = "Remove '$edge' from the '$page' expected edges.";
      }
    }
    foreach (array_diff_key(self::EXPECTED, $actual) as $page => $expected) {
      $messages[] = "Remove the unused '$page' expected edges.";
    }
    $this->assertSame([], $messages, implode("\n", $messages));
  }

}
