<?php

declare(strict_types=1);

namespace Drupal\Tests\views_ui\FunctionalJavascript;

use Drupal\ckeditor5\Plugin\CKEditor5Plugin\Heading;
use Drupal\editor\Entity\Editor;
use Drupal\filter\Entity\FilterFormat;
use Drupal\FunctionalJavascriptTests\WebDriverTestBase;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Tests the admin UI AJAX interactions.
 */
#[Group('views_ui')]
#[RunTestsInSeparateProcesses]
class AdminAjaxTest extends WebDriverTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'views_ui',
  ];

  /**
   * {@inheritdoc}
   */
  protected $defaultTheme = 'views_ui_test_theme';

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();

    $this->drupalLogin($this->createUser([
      'administer views',
    ]));
  }

  /**
   * Confirms that form_alter is triggered after AJAX rebuilds.
   */
  public function testAjaxRebuild(): void {
    \Drupal::service('theme_installer')->install(['views_ui_test_theme']);

    $this->config('system.theme')
      ->set('default', 'views_ui_test_theme')
      ->save();

    $page = $this->getSession()->getPage();
    $assert_session = $this->assertSession();

    $this->drupalGet('admin/structure/views/view/user_admin_people');
    $assert_session->pageTextContains('This is text added to the display tabs at the top');
    $assert_session->pageTextContains('This is text added to the display edit form');
    $page->clickLink('User: Name (Username)');
    $assert_session->waitForElementVisible('css', '.views-ui-dialog');
    $page->fillField('Label', 'New Title');
    $page->find('css', '.drupal-dialog-buttonset button:contains("Apply")')->press();
    $assert_session->waitForElementRemoved('css', '.views-ui-dialog');
    $assert_session->pageTextContains('This is text added to the display tabs at the top');
    $assert_session->pageTextContains('This is text added to the display edit form');
  }

  /**
   * Tests finding and selecting a handler in the add-fields dialog.
   */
  public function testFindHandler(): void {
    $this->drupalGet('admin/structure/views/view/user_admin_people');
    $assert = $this->assertSession();
    $assert->elementExists('css', '#views-add-field')->click();
    $assert->assertWaitOnAjaxRequest();

    $dialog = $assert->elementExists('css', 'dialog.views-ui-dialog[open]');
    $search = $dialog->findField('Search');
    $category = $dialog->findField('Category');
    $checkbox = $assert->fieldExists('name[views.dropbutton]', $dialog);
    $title = $assert->elementExists('css', '.title', $checkbox->getParent()->getParent()->getParent());

    $search->setValue('dropbutton');
    $this->assertTrue($title->isVisible());
    $this->assertCount(1, $this->getSession()->getPage()->findAll('css', '.filterable-option:not([hidden])'));

    $category->selectOption('User');
    $this->assertFalse($title->isVisible());
    $category->selectOption('- All -');
    $this->assertTrue($title->isVisible());
    $title->click();
    $assert->elementTextContains('css', '.views-selected-options', 'Dropbutton');

    // A selection remains checked when it is filtered out.
    $search->setValue('no such field');
    $this->assertFalse($title->isVisible());
    $assert->elementTextContains('css', '.views-selected-options', 'Dropbutton');
    $search->setValue('dropbutton');
    $title->click();
    $this->assertFalse($assert->elementExists('css', '.views-selected-options')->isVisible());
  }

  /**
   * Tests closing a rearrange dialog before its updated contents arrive.
   */
  public function testCloseWhileAddingFilterGroup(): void {
    $this->drupalGet('admin/structure/views/view/user_admin_people');
    $assert = $this->assertSession();
    $assert->elementExists('css', '.views-ui-display-tab-bucket.filter .dropbutton-toggle button')->click();
    $assert->elementExists('css', '#views-rearrange-filter')->click();
    $assert->assertWaitOnAjaxRequest();

    $session = $this->getSession();
    $session->executeScript(<<<'JS'
      const send = XMLHttpRequest.prototype.send;
      XMLHttpRequest.prototype.send = function (...args) {
        XMLHttpRequest.prototype.send = send;
        window.sendFilterGroup = () => send.apply(this, args);
      };
      JS);
    $assert->elementExists('css', '#views-add-group-link')->click();
    $this->assertJsCondition('typeof window.sendFilterGroup === "function"');
    $assert->buttonExists('Close', $assert->elementExists('css', 'dialog.views-ui-dialog[open]'))->press();
    $session->executeScript('window.sendFilterGroup()');
    $assert->assertWaitOnAjaxRequest();
    $assert->elementNotExists('css', 'dialog.views-ui-dialog[open]');
  }

  /**
   * Tests the heading menu and link balloon in a text area handler.
   */
  public function testAreaEditor(): void {
    \Drupal::service('module_installer')->install(['ckeditor5']);
    $this->resetAll();
    FilterFormat::create([
      'format' => 'area_format',
      'name' => 'Area format',
      'weight' => -10,
      'roles' => ['authenticated'],
    ])->save();
    Editor::create([
      'format' => 'area_format',
      'editor' => 'ckeditor5',
      'image_upload' => ['status' => FALSE],
      'settings' => [
        'toolbar' => ['items' => ['heading', 'link']],
        'plugins' => ['ckeditor5_heading' => Heading::DEFAULT_CONFIGURATION],
      ],
    ])->save();

    $this->drupalGet('admin/structure/views/view/user_admin_people');
    $assert = $this->assertSession();
    $assert->elementExists('css', '#views-add-header')->click();
    $assert->assertWaitOnAjaxRequest();
    $this->getSession()->getPage()->checkField('name[views.area]');
    $assert->elementExists('css', '.drupal-dialog-buttonset')->pressButton('Add and configure header');
    $assert->assertWaitOnAjaxRequest();
    $editable = $assert->waitForElementVisible('css', 'dialog[open] .ck-editor__editable');
    $this->assertNotNull($editable);

    $assert->elementExists('css', '.ck-heading-dropdown button')->click();
    $heading = $assert->waitForElementVisible('css', '.ck-heading-dropdown .ck-dropdown__panel .ck-list__item button');
    $this->assertNotNull($heading);
    $heading->click();
    $editable->click();
    $assert->elementExists('css', '.ck-toolbar button[data-cke-tooltip-text^="Link"]')->click();
    $url = $assert->waitForElementVisible('css', 'dialog[open] .ck-balloon-panel_visible input.ck-input-text');
    $this->assertNotNull($url);
    $url->setValue('https://example.com');
    $this->assertJsCondition('document.activeElement.matches("dialog[open] .ck-balloon-panel_visible input.ck-input-text")');
    $assert->elementExists('css', '.ck-balloon-panel_visible .ck-link-form')->pressButton('Insert');
    $assert->elementAttributeContains('css', '.ck-editor__editable a', 'href', 'https://example.com');
  }

  /**
   * Tests body scroll.
   */
  public function testBodyScroll(): void {
    $this->drupalGet('admin/structure/views/view/user_admin_people');
    $page = $this->getSession()->getPage();
    foreach (['name[views.nothing]', 'name[views.dropbutton]'] as $field) {
      $page->find('css', '#views-add-field')->click();
      $this->assertSession()->assertWaitOnAjaxRequest();
      $page->checkField($field);
      $page->find('css', '.drupal-dialog-buttonset')->pressButton('Add and configure fields');
      $this->assertSession()->assertWaitOnAjaxRequest();
      $this->assertJsCondition('document.documentElement.style.overflow === "hidden"');
      $page->find('css', '.drupal-dialog-buttonset')->pressButton('Apply');
      $this->assertSession()->assertWaitOnAjaxRequest();

      // Check overflow.
      $this->assertJsCondition('document.documentElement.style.overflow !== "hidden"');
    }
  }

}
