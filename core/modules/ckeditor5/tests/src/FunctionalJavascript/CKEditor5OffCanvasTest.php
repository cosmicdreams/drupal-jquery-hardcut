<?php

declare(strict_types=1);

namespace Drupal\Tests\ckeditor5\FunctionalJavascript;

use Drupal\editor\Entity\Editor;
use Drupal\filter\Entity\FilterFormat;
use Drupal\Tests\ckeditor5\Traits\CKEditor5TestTrait;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Tests for CKEditor 5 to ensure correct styling in off-canvas.
 *
 * @internal
 */
#[Group('ckeditor5')]
#[RunTestsInSeparateProcesses]
class CKEditor5OffCanvasTest extends CKEditor5TestBase {

  use CKEditor5TestTrait;

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'node',
    'ckeditor5',
    'ckeditor5_test',
  ];

  /**
   * Tests if CKEditor is properly styled inside an off-canvas dialog.
   */
  public function testOffCanvasStyles(): void {
    $assert_session = $this->assertSession();
    $page = $this->getSession()->getPage();

    $this->addNewTextFormat();
    $format = FilterFormat::load('ckeditor5');
    $filter_settings = $format->filters('filter_html')->getConfiguration();
    $filter_settings['settings']['allowed_html'] .= ' <a href>';
    $format->setFilterConfig('filter_html', $filter_settings)->save();
    $editor = Editor::load('ckeditor5');
    $settings = $editor->getSettings();
    $settings['toolbar']['items'] = ['link', 'heading'];
    $editor->setSettings($settings)->save();

    $this->drupalGet('/ckeditor5_test/off_canvas');

    // The "Add Node" link triggers an off-canvas dialog with an add node form
    // that includes CKEditor.
    $page->clickLink('Add Node');
    $assert_session->waitForElementVisible('css', '#drupal-off-canvas-wrapper');
    $assert_session->assertWaitOnAjaxRequest();

    $styles = $assert_session->elementExists('css', 'style#ckeditor5-off-canvas-reset');
    $this->assertStringContainsString('#drupal-off-canvas-wrapper [data-drupal-ck-style-fence]', $styles->getHtml());

    $assert_session->elementExists('css', '.ck');

    $ckeditor_toolbar_bg_color = $this->getSession()->evaluateScript('window.getComputedStyle(document.querySelector(\'.ck.ck-toolbar\')).backgroundColor');
    $this->assertEquals('rgb(255, 255, 255)', $ckeditor_toolbar_bg_color, 'Toolbar background-color should be unaffected by off-canvas');
    // Editable area should be visible.
    $assert_session->elementExists('css', '.ck .ck-content');
    $ckeditor_editable_bg_color = $this->getSession()->evaluateScript('window.getComputedStyle(document.querySelector(\'.ck.ck-content\')).backgroundColor');
    $this->assertEquals('rgb(255, 255, 255)', $ckeditor_editable_bg_color, 'Content background-color should be unaffected by off-canvas');

    $this->chooseEditorHeading();
    $assert_session->elementExists('css', '.ck-editor__editable h2')->click();

    $this->pressEditorButton('Link');
    $input = $assert_session->waitForElementVisible('css', '.ck-balloon-panel input.ck-input-text');
    $this->assertNotNull($input);
    $this->assertLinkBalloonPosition();
    $input->setValue('/node');
    $this->dismissEditorOverlay();
    $assert_session->assertNoElementAfterWait('css', '.ck-balloon-panel_visible input.ck-input-text');
    $assert_session->elementExists('css', 'dialog#drupal-off-canvas-wrapper[open]');
  }

}
