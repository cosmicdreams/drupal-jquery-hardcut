<?php

declare(strict_types=1);

namespace Drupal\Tests\views_ui\FunctionalJavascript;

use Drupal\ckeditor5\Plugin\CKEditor5Plugin\Heading;
use Drupal\editor\Entity\Editor;
use Drupal\filter\Entity\FilterFormat;
use Drupal\FunctionalJavascriptTests\WebDriverTestBase;
use Drupal\node\Entity\NodeType;
use PHPUnit\Framework\Attributes\DataProvider;
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
  #[DataProvider('viewsThemes')]
  public function testFindHandler(string $theme): void {
    $this->useViewsTheme($theme);
    $this->drupalGet('admin/structure/views/view/user_admin_people');
    $assert = $this->assertSession();
    $assert->elementExists('css', '#views-add-field')->click();
    $assert->assertWaitOnAjaxRequest();

    $dialog = $assert->elementExists('css', 'dialog.views-ui-dialog[open]');
    $selected = $assert->elementExists('css', '.views-add-form-selected', $dialog);
    $this->assertFalse($selected->isVisible());
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
    $this->assertTrue($selected->isVisible());
    $assert->elementTextContains('css', '.views-selected-options', 'Dropbutton');

    // A selection remains checked when it is filtered out.
    $search->setValue('no such field');
    $this->assertFalse($title->isVisible());
    $assert->elementTextContains('css', '.views-selected-options', 'Dropbutton');
    $search->setValue('dropbutton');
    $title->click();
    $this->assertFalse($selected->isVisible());
  }

  /**
   * Tests Select all before and after the filter behavior is attached.
   */
  #[DataProvider('viewsThemes')]
  public function testSelectAll(string $theme): void {
    $this->useViewsTheme($theme);
    \Drupal::service('module_installer')->install(['node']);
    $this->resetAll();
    NodeType::create(['type' => 'page', 'name' => 'Page'])->save();
    NodeType::create(['type' => 'article', 'name' => 'Article'])->save();
    $this->config('views.view.content')
      ->set('display.default.display_options.filters.type.exposed', FALSE)
      ->save();
    $this->drupalGet('admin/structure/views/view/content');

    $assert = $this->assertSession();
    $filter = $assert->elementExists('css', 'a[href$="/filter/type"]');
    $filter->click();
    $assert->assertWaitOnAjaxRequest();
    $dialog = $assert->elementExists('css', 'dialog.views-ui-dialog[open]');
    $select_all = $assert->fieldExists('Select all', $dialog);
    $this->assertTrue($select_all->isVisible());
    $select_all->check();
    $page = $assert->fieldExists('options[value][page]', $dialog);
    $this->assertTrue($page->isChecked());
    $this->assertTrue($assert->fieldExists('options[value][article]', $dialog)->isChecked());
    $page->uncheck();
    $this->assertFalse($select_all->isChecked());

    $assert->buttonExists('Close', $dialog)->press();
    $this->getSession()->executeScript('delete Drupal.behaviors.viewsFilterConfigSelectAll');
    $filter->click();
    $assert->assertWaitOnAjaxRequest();
    $this->assertFalse($assert->fieldExists('Select all')->isVisible());
  }

  /**
   * Provides core themes for Views UI visibility tests.
   */
  public static function viewsThemes(): array {
    return [
      'Stark' => ['stark'],
      'Default Admin' => ['default_admin'],
      'Starterkit' => ['starterkit_theme'],
    ];
  }

  /**
   * Sets the current theme for the Views editor and its dialog requests.
   */
  private function useViewsTheme(string $theme): void {
    $this->container->get('theme_installer')->install([$theme]);
    $this->config('system.theme')->set('default', $theme)->save();
  }

  /**
   * Tests replacing a rearrange dialog while its response is loading assets.
   */
  public function testCloseWhileAddingFilterGroup(): void {
    $this->drupalGet('admin/structure/views/view/user_admin_people');
    $assert = $this->assertSession();
    $assert->elementExists('css', '.views-ui-display-tab-bucket.filter .dropbutton-toggle button')->click();
    $assert->elementExists('css', '#views-rearrange-filter')->click();
    $assert->assertWaitOnAjaxRequest();

    $session = $this->getSession();
    $session->executeScript(<<<'JS'
      const prototype = Drupal.Ajax.prototype;
      const queue = prototype.commandExecutionQueue;
      prototype.commandExecutionQueue = function (response, status) {
        prototype.commandExecutionQueue = queue;
        this.commands.add_css = () => new Promise((resolve) => {
          window.finishFilterGroupAssets = resolve;
        });
        return queue.call(this, [{ command: 'add_css', data: [] }, ...Object.values(response)], status);
      };
      JS);
    $assert->elementExists('css', '#views-add-group-link')->click();
    $this->assertJsCondition('typeof window.finishFilterGroupAssets === "function"');
    $dialog = $assert->elementExists('css', 'dialog.views-ui-dialog[open]');
    $assert->buttonExists('Close', $dialog)->press();
    $assert->elementExists('css', '#views-add-field')->click();
    $this->assertJsCondition('document.querySelector("dialog[open] .views-selected-options") !== null');

    $session->executeScript('window.finishFilterGroupAssets()');
    $assert->assertWaitOnAjaxRequest();
    $assert->elementExists('css', 'dialog[open] .views-selected-options');
    $assert->elementNotExists('css', 'dialog[open] #views-add-group-link');
  }

  /**
   * Tests the heading menu and link balloon in a text area handler.
   */
  public function testAreaEditor(): void {
    \Drupal::service('module_installer')->install(['ckeditor5', 'views_ui_test']);
    $this->resetAll();
    \Drupal::state()->set('views_ui_test.enable_area_editor', TRUE);
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
    $assert->elementExists('xpath', '//button[span[text()="Link"]]')->click();
    $url = $assert->waitForElementVisible('css', 'dialog[open] .ck-balloon-panel_visible input[inputmode=url]');
    $this->assertNotNull($url);
    $url->setValue('https://example.com');
    $this->assertJsCondition('document.activeElement.matches("dialog[open] .ck-balloon-panel_visible input[inputmode=url]")');
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
