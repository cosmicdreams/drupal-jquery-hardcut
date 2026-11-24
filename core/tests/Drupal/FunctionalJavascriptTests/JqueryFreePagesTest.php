<?php

declare(strict_types=1);

namespace Drupal\FunctionalJavascriptTests;

use Drupal\Tests\field\Traits\EntityReferenceFieldCreationTrait;
use Drupal\Tests\node\Traits\ContentTypeCreationTrait;
use Drupal\Tests\node\Traits\NodeCreationTrait;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Ensures representative core pages load no jQuery assets or globals.
 */
#[Group('javascript')]
#[RunTestsInSeparateProcesses]
class JqueryFreePagesTest extends WebDriverTestBase {

  use ContentTypeCreationTrait;
  use EntityReferenceFieldCreationTrait;
  use NodeCreationTrait;

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
    $assert_session = $this->assertSession();

    $this->drupalLogin($this->createUser([], NULL, TRUE));
    $this->drupalGet('<front>');
    $assert_session->elementExists('css', 'main');
    $this->assertNoJquery('front');

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
    $this->assertNoJquery('node-add');

    // Default Admin uses an accordion on node forms. Use the content type
    // form to exercise its vertical tabs.
    $this->drupalGet('admin/structure/types/manage/article');
    $this->clickLink('Publishing options');
    $this->assertNotNull($assert_session->waitForElementVisible('css', '#edit-workflow'));
    $this->assertNoJquery('vertical-tabs');

    $this->drupalGet('admin/structure/block');
    $assert_session->elementExists('css', 'a.use-ajax[data-dialog-type="modal"]')->click();
    $assert_session->assertWaitOnAjaxRequest();
    $assert_session->elementExists('css', '#drupal-modal a.use-ajax[href*="/admin/structure/block/add/"]')->click();
    $assert_session->assertWaitOnAjaxRequest();
    $assert_session->elementTextContains('css', 'dialog[open]', 'Configure block');
    $this->assertNoJquery('block-layout');

    $this->drupalGet('admin/structure/types/manage/article/display/default');
    $handle = $assert_session->elementExists('css', 'tr:has([name="fields[body][region]"]) .tabledrag-handle');
    $handle->dragTo($assert_session->elementExists('css', '.region-hidden-title'));
    $assert_session->assertWaitOnAjaxRequest();
    $this->assertSame('hidden', $assert_session->fieldExists('fields[body][region]')->getValue());
    $this->assertNoJquery('field-display');

    $this->drupalGet('admin/people/permissions');
    $assert_session->elementExists('css', '#permissions tbody tr');
    $filter = $assert_session->elementExists('css', 'input.table-filter-text');
    $filter->setValue('no-permission-matches-this-value');
    $this->getSession()->getDriver()->keyUp($filter->getXpath(), ' ');
    $this->assertTrue($this->getSession()->wait(2000, 'Array.from(document.querySelectorAll("#permissions tbody tr")).every(row => row.offsetParent === null)'));
    $this->assertNoJquery('permissions');

    $this->drupalGet('ajax-test/dialog');
    $this->clickLink('Link 1 (modal)');
    $assert_session->assertWaitOnAjaxRequest();
    $assert_session->elementExists('css', 'dialog[open] #drupal-modal');
    $this->assertNoJquery('modal');

    $this->drupalGet('off-canvas-test-links');
    $this->clickLink('Open side panel 1');
    $assert_session->assertWaitOnAjaxRequest();
    $assert_session->elementTextContains('css', '#drupal-off-canvas', 'Thing 1 says hello');
    $this->assertNoJquery('off-canvas');
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
    $this->drupalLogin($this->createUser([
      'administer views',
      'access content overview',
      'view the administration theme',
    ]));

    $this->drupalGet('admin/structure/views');
    $this->assertSession()->elementExists('css', 'input.views-filter-text');
    $this->assertNoJquery('Views listing');

    $this->drupalGet('admin/structure/views/view/content');
    $this->assertSession()->elementExists('css', '#views-add-field')->click();
    $this->assertSession()->assertWaitOnAjaxRequest();
    $this->assertSession()->elementExists('css', 'dialog[open] .filterable-option');
    $this->assertNoJquery('Views editor');

    $this->drupalGet('admin/content');
    $this->getSession()->getPage()->pressButton('Filter');
    $this->assertSession()->assertWaitOnAjaxRequest();
    $this->assertNoJquery('Ajax content view');
  }

  /**
   * Checks the editor runtime and configuration form without theme libraries.
   */
  public function testEditorPagesWithoutJquery(): void {
    \Drupal::service('theme_installer')->install(['stark']);
    $this->config('system.theme')->set('default', 'stark')->set('admin', 'stark')->save();
    $this->config('system.performance')->set('js.preprocess', FALSE)->save();
    $this->createContentType(['type' => 'page', 'name' => 'Page']);
    $this->drupalLogin($this->createUser([
      'administer filters',
      'create page content',
      'use text format basic_html',
      'view the administration theme',
    ]));

    $this->drupalGet('node/add/page');
    $this->assertSession()->elementExists('css', 'input[data-editor-for][name="body[0][format]"][value="basic_html"]');
    $this->assertNotNull($this->assertSession()->waitForElementVisible('css', '.ck-editor'));
    $this->assertNoJquery('CKEditor 5 runtime');

    $this->drupalGet('admin/config/content/formats/manage/basic_html');
    $this->assertSession()->elementExists('css', '#ckeditor5-toolbar-app [role="listbox"]');
    $this->assertNoJquery('CKEditor 5 toolbar builder');
  }

  /**
   * Checks active globals and loaded resources, including Ajax additions.
   */
  private function assertNoJquery(string $page): void {
    $this->assertSame('undefined', $this->getSession()->evaluateScript('typeof window.jQuery'), $page);
    $this->assertSame('undefined', $this->getSession()->evaluateScript('typeof window.$?.fn?.jquery'), $page);
    $this->assertSame([], $this->getSession()->evaluateScript(<<<'JS'
      (() => {
        const resources = [
          ...performance.getEntriesByType('resource').map(({ name }) => name),
          ...Array.from(document.querySelectorAll('script[src], link[rel~="stylesheet"][href]'),
            (asset) => asset.src || asset.href),
        ];
        return resources.filter((url) => /(?:^|\/)jquery(?:[./@-]|$)/i.test(new URL(url).pathname));
      })()
      JS), $page);
  }

}
