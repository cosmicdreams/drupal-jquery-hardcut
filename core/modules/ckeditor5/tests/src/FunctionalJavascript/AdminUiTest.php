<?php

declare(strict_types=1);

namespace Drupal\Tests\ckeditor5\FunctionalJavascript;

use Drupal\editor\Entity\Editor;
use Drupal\FunctionalJavascriptTests\DrupalSelenium2Driver;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

// cspell:ignore sourceediting
/**
 * Tests for CKEditor 5 in the admin UI.
 *
 * @internal
 */
#[Group('ckeditor5')]
#[RunTestsInSeparateProcesses]
class AdminUiTest extends CKEditor5TestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'media_library',
    'editor_test',
    'ckeditor5_incompatible_filter_test',
  ];

  /**
   * Confirm settings only trigger AJAX when select value is CKEditor 5.
   */
  public function testSettingsOnlyFireAjaxWithCkeditor5(): void {
    $page = $this->getSession()->getPage();
    $assert_session = $this->assertSession();
    $this->addNewTextFormat();
    $this->addNewTextFormat('unicorn');

    $this->drupalGet('admin/config/content/formats/manage/ckeditor5');

    // Enable media embed to trigger an AJAX rebuild.
    $this->assertTrue($page->hasUncheckedField('filters[media_embed][status]'));
    $this->assertNoAjaxRequestTriggered();
    $page->checkField('filters[media_embed][status]');
    $assert_session->assertExpectedAjaxRequest(1);

    // Perform the same steps as above with CKEditor, and confirm AJAX callbacks
    // are not triggered on settings changes.
    $this->drupalGet('admin/config/content/formats/manage/unicorn');

    // Enable media embed to confirm a format not using CKEditor 5 will not
    // trigger an AJAX rebuild.
    $this->assertTrue($page->hasUncheckedField('filters[media_embed][status]'));
    $page->checkField('filters[media_embed][status]');
    $this->assertNoAjaxRequestTriggered();

    // Confirm that AJAX updates happen when attempting to switch to CKEditor 5,
    // even if prevented from doing so by validation.
    $this->drupalGet('admin/config/content/formats/add');
    $this->assertFalse($assert_session->elementExists('css', '#edit-name-machine-name-suffix')->isVisible());
    $name_field = $page->findField('name');
    $name_field->setValue('trigger validator');
    $this->assertTrue($assert_session->elementExists('css', '#edit-name-machine-name-suffix')->isVisible());

    // Enable a filter that is incompatible with CKEditor 5, so validation is
    // triggered when attempting to switch.
    $incompatible_filter_name = 'filters[filter_incompatible][status]';
    $this->assertTrue($page->hasUncheckedField($incompatible_filter_name));
    $page->checkField($incompatible_filter_name);
    $this->assertNoAjaxRequestTriggered();

    $page->selectFieldOption('editor[editor]', 'ckeditor5');
    $assert_session->assertExpectedAjaxRequest(1);

    $filter_warning = 'CKEditor 5 only works with HTML-based text formats. The "A TYPE_MARKUP_LANGUAGE filter incompatible with CKEditor 5" (filter_incompatible) filter implies this text format is not HTML anymore.';

    // The presence of this validation error message confirms the AJAX callback
    // was invoked.
    $assert_session->pageTextContains($filter_warning);

    // Disable the incompatible filter. This should trigger another AJAX rebuild
    // which will include the removal of the validation error as the issue has
    // been corrected.
    $this->assertTrue($page->hasCheckedField($incompatible_filter_name));
    $page->uncheckField($incompatible_filter_name);
    $assert_session->assertExpectedAjaxRequest(2);
    $assert_session->pageTextNotContains($filter_warning);
  }

  /**
   * Asserts that no (new) AJAX requests were triggered.
   *
   * @param int $expected_cumulative_ajax_request_count
   *   The number of expected observed XHR requests since the page was loaded.
   */
  protected function assertNoAjaxRequestTriggered(int $expected_cumulative_ajax_request_count = 0): void {
    // In case of no requests triggered at all yet.
    if ($expected_cumulative_ajax_request_count === 0) {
      $result = $this->getSession()->evaluateScript(<<<JS
      (function() {
        return window.drupalCumulativeXhrCount;
      }())
JS);
      $this->assertSame(0, $result);
    }
    else {
      // In case of the non-first AJAX request, ensure that no AJAX requests are
      // in progress.
      try {
        $this->assertSession()->assertWaitOnAjaxRequest(500);
      }
      catch (\RuntimeException $e) {
        throw new \LogicException(sprintf('This call to %s claims there no AJAX request was triggered, but this is wrong: %s.', __METHOD__, $e->getMessage()));
      }
      catch (\LogicException $e) {
        // This is the intent: ::assertWaitOnAjaxRequest() should detect an
        // "incorrect" call, because this assertion is asserting *no* AJAX
        // requests have been triggered.
        assert(str_contains($e->getMessage(), 'Unnecessary'));

        $result = $this->getSession()->evaluateScript(<<<JS
        (function() {
          return window.drupalCumulativeXhrCount;
        }())
JS);
        $this->assertSame($expected_cumulative_ajax_request_count, $result);
      }
    }

    // Now that there definitely is no more AJAX request in progress, count the
    // number of actual XHR requests, ensure they match.
    $javascript = <<<JS
(function(){
  return window.performance
    .getEntries()
    .filter(entry => entry.initiatorType === 'xmlhttprequest')
    .length
})()
JS;
    $this->assertSame($expected_cumulative_ajax_request_count, $this->getSession()->evaluateScript($javascript));
  }

  /**
   * CKEditor 5's filter UI modifications should not break it for other editors.
   */
  public function testUnavailableFiltersHiddenWhenSwitching(): void {
    $page = $this->getSession()->getPage();
    $assert_session = $this->assertSession();
    $this->createNewTextFormat($page, $assert_session, 'unicorn');
    $assert_session->pageTextNotContains('Filter settings');

    // Switching to CKEditor 5 should keep the filter settings hidden.
    $page->selectFieldOption('editor[editor]', 'ckeditor5');
    $assert_session->assertWaitOnAjaxRequest();
    $assert_session->pageTextNotContains('Filter settings');
  }

  /**
   * Tests moving toolbar buttons with the keyboard and announcing the result.
   */
  public function testKeyboardToolbarMoves(): void {
    $this->addNewTextFormat();
    $this->drupalGet('admin/config/content/formats/manage/ckeditor5');
    $assert = $this->assertSession();
    $active = '[data-button-list="ckeditor5-toolbar-active-buttons"]';
    $available = '[data-button-list="ckeditor5-toolbar-available-buttons"]';
    $button = '[data-id="blockQuote"]';
    $announcement = '#drupal-live-announce';

    // Pressing the outward arrow at either end leaves focus and the tooltip
    // in place.
    $driver = $this->getSession()->getDriver();
    $this->assertInstanceOf(DrupalSelenium2Driver::class, $driver);
    foreach (['first' => "\u{E012}", 'last' => "\u{E014}"] as $edge => $key) {
      $item = "$active li:$edge-child";
      $assert->elementExists('css', $item)->focus();
      $driver->getWebDriverSession()->postActions([
        'actions' => [
          [
            'type' => 'key',
            'id' => 'keyboard',
            'actions' => [
              ['type' => 'keyDown', 'value' => $key],
              ['type' => 'keyUp', 'value' => $key],
            ],
          ],
        ],
      ]);
      $this->assertJsCondition("document.activeElement.matches('$item')");
      $tooltip = $assert->elementExists('css', "$item .ckeditor5-toolbar-tooltip");
      $this->assertTrue($tooltip->isVisible());
    }

    $assert->elementExists('css', "$available $button")->focus();
    $this->triggerKeyUp("$available $button", 'ArrowDown');
    $assert->assertWaitOnAjaxRequest();
    $assert->elementExists('css', "$active li:last-child$button");
    $this->assertJsCondition('document.activeElement.dataset.id === "blockQuote"');
    $this->assertNotNull($assert->waitForElement('css', "$announcement:contains('has been moved to the active toolbar')"));

    $this->triggerKeyUp("$active $button", 'ArrowLeft');
    $assert->assertWaitOnAjaxRequest();
    $assert->elementExists('css', "$active li:nth-last-child(2)$button");
    $this->assertNotNull($assert->waitForElement('css', "$announcement:contains('has moved to position')"));
    $this->assertJsCondition('document.activeElement.dataset.id === "blockQuote"');

    $this->triggerKeyUp("$active $button", 'ArrowRight');
    $assert->assertWaitOnAjaxRequest();
    $assert->elementExists('css', "$active li:last-child$button");
    $count = count($this->getSession()->getPage()->findAll('css', "$active li"));
    $this->assertNotNull($assert->waitForElement('css', "$announcement:contains('has moved to position $count of $count')"));
    $this->assertJsCondition('document.activeElement.dataset.id === "blockQuote"');
    $this->triggerKeyUp("$active $button", 'ArrowRight');
    $assert->elementExists('css', "$active li:last-child$button");

    $this->triggerKeyUp("$active $button", 'ArrowUp');
    $assert->assertWaitOnAjaxRequest();
    $assert->elementExists('css', "$available $button");
    $this->assertNotNull($assert->waitForElement('css', "$announcement:contains('has been removed from the active toolbar')"));
    $this->assertJsCondition('document.activeElement.dataset.id === "blockQuote"');

    $divider = '[data-id="divider"]';
    $divider_template = '[data-button-list="ckeditor5-toolbar-divider-buttons"] ' . $divider;
    $neighbor = $assert->elementExists('css', "$active li:last-child")->getAttribute('data-id');
    $assert->elementExists('css', $divider_template)->focus();
    $this->triggerKeyUp($divider_template, 'ArrowDown');
    $assert->assertWaitOnAjaxRequest();
    $this->assertJsCondition("document.activeElement.matches('$active li:last-child$divider')");
    $this->triggerKeyUp("$active li:last-child$divider", 'ArrowUp');
    $assert->assertWaitOnAjaxRequest();
    $this->assertJsCondition("document.activeElement.matches('$active li:last-child[data-id=\"$neighbor\"]')");
  }

  /**
   * Tests focus when removing the only item from the active toolbar.
   */
  public function testRemoveLastToolbarDivider(): void {
    $this->addNewTextFormat();
    $editor = Editor::load('ckeditor5');
    $settings = $editor->getSettings();
    $settings['toolbar']['items'] = ['|'];
    $editor->setSettings($settings)->save();
    $this->drupalGet('admin/config/content/formats/manage/ckeditor5');
    $assert = $this->assertSession();
    $active = '[data-button-list="ckeditor5-toolbar-active-buttons"]';
    $divider = $assert->elementExists('css', "$active li:only-child[data-id=divider]");
    $divider->focus();

    $this->getSession()->executeScript(<<<'JS'
      const divider = document.activeElement;
      divider.dispatchEvent(new KeyboardEvent('keyup', { key: 'ArrowUp', bubbles: true }));
      window.focusAfterDividerRemoval = document.activeElement.dataset.drupalSelector;
      JS);
    $this->assertSame('ckeditor5-toolbar-region', $this->getSession()->evaluateScript('window.focusAfterDividerRemoval'));
    $assert->assertWaitOnAjaxRequest();
    $assert->elementNotExists('css', "$active li");
    $this->assertJsCondition('document.activeElement.dataset.drupalSelector === "ckeditor5-toolbar-region"');
  }

  /**
   * Tests pointer drops of a toolbar button and a cloned divider.
   */
  public function testPointerToolbarDrops(): void {
    $this->addNewTextFormat();
    $this->getSession()->resizeWindow(1400, 900);
    $this->drupalGet('admin/config/content/formats/manage/ckeditor5');
    $assert = $this->assertSession();
    $active = '[data-button-list=ckeditor5-toolbar-active-buttons]';
    $available = '[data-button-list=ckeditor5-toolbar-available-buttons]';
    $dividers = '[data-button-list=ckeditor5-toolbar-divider-buttons]';
    $button = '[data-id=blockQuote]';
    $divider = '[data-id=divider]';

    $this->dragToolbarItem("$available $button", "$active li:first-child");
    $assert->assertWaitOnAjaxRequest();
    $assert->elementExists('css', "$active $button");
    $assert->elementNotExists('css', "$available $button");

    $this->dragToolbarItem("$dividers $divider", "$active li:first-child");
    $assert->assertWaitOnAjaxRequest();
    $assert->elementExists('css', "$active $divider");
    $assert->elementExists('css', "$dividers $divider");
  }

  /**
   * Drags an item onto an active toolbar item with WebDriver pointer actions.
   */
  private function dragToolbarItem(string $source, string $target): void {
    $session = $this->getSession();
    $driver = $session->getDriver();
    $this->assertInstanceOf(DrupalSelenium2Driver::class, $driver);
    $selectors = json_encode([$source, $target], JSON_THROW_ON_ERROR);
    [$from, $to] = $session->evaluateScript(<<<JS
      {$selectors}.map((selector) => {
        const { x, y, width, height } = document.querySelector(selector).getBoundingClientRect();
        return { x: Math.round(x + width / 2), y: Math.round(y + height / 2) };
      })
      JS);
    $driver->getWebDriverSession()->postActions([
      'actions' => [
        [
          'type' => 'pointer',
          'id' => 'toolbar',
          'parameters' => ['pointerType' => 'mouse'],
          'actions' => [
            ['type' => 'pointerMove'] + $from,
            ['type' => 'pointerDown', 'button' => 0],
            ['type' => 'pointerMove', 'duration' => 100, 'x' => $from['x'] + 6, 'y' => $from['y']],
            ['type' => 'pointerMove', 'duration' => 200] + $to,
            ['type' => 'pause', 'duration' => 300],
            ['type' => 'pointerUp', 'button' => 0],
          ],
        ],
      ],
    ]);
  }

  /**
   * Test that filter settings are only visible when the filter is enabled.
   */
  #[DataProvider('filterThemes')]
  public function testFilterCheckboxesToggleSettings(string $theme): void {
    $this->container->get('theme_installer')->install([$theme]);
    $this->config('system.theme')->set('default', $theme)->set('admin', $theme)->save();
    $page = $this->getSession()->getPage();
    $assert_session = $this->assertSession();

    $this->createNewTextFormat($page, $assert_session);

    $this->assertFilterCheckboxTogglesSettings('[href^="#edit-filters-media-embed-settings"]');

    $this->getSession()->resizeWindow(600, 900);
    $this->createNewTextFormat($page, $assert_session);
    $assert_session->elementNotExists('css', '.vertical-tabs__menu');
    $panel_selector = 'details[data-drupal-selector="edit-filters-media-embed-settings"]';
    $this->assertFilterCheckboxTogglesSettings($panel_selector);

    // The settings panel remains usable when rendered as a details element.
    $page->checkField('filters[media_embed][status]');
    $assert_session->assertWaitOnAjaxRequest();
    $summary = $assert_session->elementExists('css', "$panel_selector > summary");
    $assert_session->elementExists('css', "{$panel_selector}[open]");
    $summary->click();
    $assert_session->elementNotExists('css', "{$panel_selector}[open]");
    $summary->click();
    $assert_session->elementExists('css', "{$panel_selector}[open]");
  }

  /**
   * Checks that the filter checkbox toggles its processing row and settings.
   */
  private function assertFilterCheckboxTogglesSettings(string $settings_selector): void {
    $page = $this->getSession()->getPage();
    $assert = $this->assertSession();
    $row_selector = '#filter-order tr:has([data-drupal-selector="edit-filters-media-embed-weight"])';
    $this->assertFalse($assert->elementExists('css', $row_selector)->isVisible());
    $this->assertFalse($assert->elementExists('css', $settings_selector)->isVisible());

    $page->checkField('filters[media_embed][status]');
    $assert->assertWaitOnAjaxRequest();
    $this->assertNotNull($assert->waitForElementVisible('css', $row_selector));
    $this->assertNotNull($assert->waitForElementVisible('css', $settings_selector));

    $page->uncheckField('filters[media_embed][status]');
    $assert->assertWaitOnAjaxRequest();
    $this->assertFalse($assert->elementExists('css', $row_selector)->isVisible());
    $this->assertFalse($assert->elementExists('css', $settings_selector)->isVisible());
  }

  /**
   * Themes with different styling for filter settings.
   */
  public static function filterThemes(): array {
    return [
      'Stark' => ['stark'],
      'Default Admin' => ['default_admin'],
      'Starterkit' => ['starterkit_theme'],
    ];
  }

  /**
   * Tests that image upload settings (stored out of band) are validated too.
   */
  public function testImageUploadSettingsAreValidated(): void {
    $page = $this->getSession()->getPage();
    $assert_session = $this->assertSession();

    $this->addNewTextFormat();
    $this->drupalGet('admin/config/content/formats/manage/ckeditor5');

    // Add the image plugin to the CKEditor 5 toolbar.
    $this->assertNotEmpty($assert_session->waitForElement('css', '.ckeditor5-toolbar-item-drupalInsertImage'));
    $this->triggerKeyUp('.ckeditor5-toolbar-item-drupalInsertImage', 'ArrowDown');
    $assert_session->assertExpectedAjaxRequest(1);

    // Open the vertical tab with its settings.
    $page->find('css', '[href^="#edit-editor-settings-plugins-ckeditor5-image"]')->click();
    $this->assertTrue($assert_session->waitForText('Enable image uploads'));

    // Check the "Enable image uploads" checkbox.
    $assert_session->checkboxNotChecked('editor[settings][plugins][ckeditor5_image][status]');
    $page->checkField('editor[settings][plugins][ckeditor5_image][status]');
    $assert_session->assertExpectedAjaxRequest(2);

    // Enter a nonsensical maximum file size.
    $page->fillField('editor[settings][plugins][ckeditor5_image][max_size]', 'foobar');
    $this->assertNoRealtimeValidationErrors();

    // Enable another toolbar item to trigger validation.
    $this->triggerKeyUp('.ckeditor5-toolbar-item-sourceEditing', 'ArrowDown');
    $assert_session->assertExpectedAjaxRequest(3);

    // The expected validation error must be present.
    $assert_session->elementExists('css', '[role=alert]:contains("This value must be a number of bytes, optionally with a unit such as "MB" or "megabytes".")');

    // Enter no maximum file size because it is optional, this should result in
    // no validation error and it being set to `null`.
    $page->findField('editor[settings][plugins][ckeditor5_image][max_size]')->setValue('');

    // Remove a toolbar item to trigger validation.
    $this->triggerKeyUp('.ckeditor5-toolbar-item-sourceEditing', 'ArrowUp');
    $assert_session->assertExpectedAjaxRequest(4);

    // No more validation errors, let's save.
    $this->assertNoRealtimeValidationErrors();
    $page->pressButton('Save configuration');
    $assert_session->pageTextContains('The text format ckeditor5 has been updated');
  }

  /**
   * Ensure CKEditor 5 admin UI's real-time validation errors do not accumulate.
   */
  public function testMessagesDoNotAccumulate(): void {
    $page = $this->getSession()->getPage();
    $assert_session = $this->assertSession();

    $this->addNewTextFormat();
    $this->drupalGet('admin/config/content/formats/manage/ckeditor5');

    // Add the source editing plugin to the CKEditor 5 toolbar.
    $this->assertNotEmpty($assert_session->waitForElement('css', '.ckeditor5-toolbar-item-sourceEditing'));
    $this->triggerKeyUp('.ckeditor5-toolbar-item-sourceEditing', 'ArrowDown');
    $assert_session->assertWaitOnAjaxRequest();

    $find_validation_error_messages = function () use ($page): array {
      return $page->findAll('css', '[role=alert]:contains("The following tag(s) are already supported by enabled plugins and should not be added to the Source Editing "Manually editable HTML tags" field: Bold (<strong>).")');
    };

    // No validation errors when we start.
    $this->assertCount(0, $find_validation_error_messages());

    // Configure Source Editing to allow editing `<strong>` to trigger
    // validation error.
    $assert_session->waitForText('Source editing');
    $page->find('css', '[href^="#edit-editor-settings-plugins-ckeditor5-sourceediting"]')->click();
    $assert_session->waitForText('Manually editable HTML tags');
    $source_edit_tags_field = $assert_session->fieldExists('editor[settings][plugins][ckeditor5_sourceEditing][allowed_tags]');
    $source_edit_tags_field->setValue('<strong>');
    $assert_session->assertWaitOnAjaxRequest();
    $this->assertCount(1, $find_validation_error_messages());

    // Revert Source Editing it: validation messages should be gone.
    $source_edit_tags_field->setValue('');
    $assert_session->assertWaitOnAjaxRequest();
    $this->assertCount(0, $find_validation_error_messages());

    // Add `<strong>` again: validation messages should be back.
    $source_edit_tags_field->setValue('<strong>');
    $assert_session->assertWaitOnAjaxRequest();
    $this->assertCount(1, $find_validation_error_messages());
  }

  /**
   * Tests the plugin settings form section.
   */
  public function testPluginSettingsFormSection(): void {
    $page = $this->getSession()->getPage();
    $assert_session = $this->assertSession();

    $this->createNewTextFormat($page, $assert_session);

    // The default toolbar only enables the configurable heading plugin and the
    // non-configurable bold and italic plugins.
    $assert_session->fieldValueEquals('editor[settings][toolbar][items]', '["heading","bold","italic"]');
    // The heading plugin config form should be present.
    $assert_session->elementExists('css', '[data-drupal-selector="edit-editor-settings-plugins-ckeditor5-heading"]');

    // Remove the heading plugin from the toolbar.
    $this->triggerKeyUp('.ckeditor5-toolbar-item-heading', 'ArrowUp');
    $assert_session->assertWaitOnAjaxRequest();

    // The heading plugin config form should no longer be present.
    $assert_session->elementNotExists('css', '[data-drupal-selector="edit-editor-settings-plugins-ckeditor5-heading"]');
    // The plugin settings wrapper should still be present, but empty.
    $assert_session->elementExists('css', '#plugin-settings-wrapper');
    $assert_session->elementNotContains('css', '#plugin-settings-wrapper', '<div');

    // Enable the source plugin.
    $this->triggerKeyUp('.ckeditor5-toolbar-item-sourceEditing', 'ArrowDown');
    $assert_session->assertWaitOnAjaxRequest();

    // The source plugin config form should be present.
    $assert_session->elementExists('css', '[data-drupal-selector="edit-editor-settings-plugins-ckeditor5-sourceediting"]');

    // The filter-dependent configurable plugin should not be present.
    $assert_session->elementNotExists('css', '[data-drupal-selector="edit-editor-settings-plugins-media-media"]');

    // Enable the filter that the configurable plugin depends on.
    $this->assertTrue($page->hasUncheckedField('filters[media_embed][status]'));
    $page->checkField('filters[media_embed][status]');
    $assert_session->assertWaitOnAjaxRequest();

    // The filter-dependent configurable plugin should be present.
    $assert_session->elementExists('css', '[data-drupal-selector="edit-editor-settings-plugins-media-media"]');
  }

  /**
   * Tests the language config form.
   */
  public function testLanguageConfigForm(): void {
    $page = $this->getSession()->getPage();
    $assert_session = $this->assertSession();

    $this->createNewTextFormat($page, $assert_session);

    // The language plugin config form should not be present.
    $assert_session->elementNotExists('css', '[data-drupal-selector="edit-editor-settings-plugins-ckeditor5-language"]');

    $this->assertNotEmpty($assert_session->waitForElement('css', '.ckeditor5-toolbar-item-textPartLanguage'));
    $this->triggerKeyUp('.ckeditor5-toolbar-item-textPartLanguage', 'ArrowDown');
    $assert_session->assertWaitOnAjaxRequest();

    // The CKEditor 5 module should warn that `<span>` cannot be created.
    $assert_session->waitForElement('css', '[role=alert][data-drupal-message-type="warning"]:contains("The Language plugin needs another plugin to create <span>, for it to be able to create the following attributes: <span lang dir>. Enable a plugin that supports creating this tag. If none exists, you can configure the Source Editing plugin to support it.")');

    // Make `<span>` creatable.
    $this->assertNotEmpty($assert_session->elementExists('css', '.ckeditor5-toolbar-item-sourceEditing'));
    $this->triggerKeyUp('.ckeditor5-toolbar-item-sourceEditing', 'ArrowDown');
    $assert_session->assertWaitOnAjaxRequest();
    // The Source Editing plugin settings form should now be present and should
    // have no allowed tags configured.
    $page->clickLink('Source editing');
    $this->assertNotNull($assert_session->waitForElementVisible('css', '[data-drupal-selector="edit-editor-settings-plugins-ckeditor5-sourceediting-allowed-tags"]'));
    $javascript = <<<JS
      const allowedTags = document.querySelector('[data-drupal-selector="edit-editor-settings-plugins-ckeditor5-sourceediting-allowed-tags"]');
      allowedTags.value = '<span>';
      allowedTags.dispatchEvent(new Event('input'));
JS;
    $this->getSession()->executeScript($javascript);
    // Dispatching an `input` event does not work in WebDriver. Enabling another
    // toolbar item which has no associated HTML elements forces it.
    $this->triggerKeyUp('.ckeditor5-toolbar-item-undo', 'ArrowDown');
    $assert_session->assertWaitOnAjaxRequest();

    // Confirm there are no longer any warnings.
    $assert_session->waitForElementRemoved('css', '[data-drupal-messages] [role="alert"]');

    // The language plugin config form should now be present.
    $assert_session->elementExists('css', '[data-drupal-selector="edit-editor-settings-plugins-ckeditor5-language"]');

    // It must also be possible to remove the language plugin again.
    $this->triggerKeyUp('.ckeditor5-toolbar-item-textPartLanguage', 'ArrowUp');
    $assert_session->assertWaitOnAjaxRequest();

    // The language plugin config form should not be present anymore.
    $assert_session->elementNotExists('css', '[data-drupal-selector="edit-editor-settings-plugins-ckeditor5-language"]');
  }

}
