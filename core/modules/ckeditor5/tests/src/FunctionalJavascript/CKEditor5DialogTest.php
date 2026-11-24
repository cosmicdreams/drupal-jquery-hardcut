<?php

declare(strict_types=1);

namespace Drupal\Tests\ckeditor5\FunctionalJavascript;

use Drupal\ckeditor5\Plugin\CKEditor5Plugin\Heading;
use Drupal\ckeditor5\Plugin\Editor\CKEditor5;
use Drupal\editor\Entity\Editor;
use Drupal\filter\Entity\FilterFormat;
use Drupal\Tests\ckeditor5\Traits\CKEditor5TestTrait;
use Drupal\user\RoleInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use Symfony\Component\Validator\ConstraintViolationInterface;

/**
 * Tests for CKEditor 5 dialog behavior.
 *
 * @internal
 */
#[Group('ckeditor5')]
#[RunTestsInSeparateProcesses]
class CKEditor5DialogTest extends CKEditor5TestBase {

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
   * Tests if CKEditor 5 tooltips can be interacted with in dialogs.
   */
  #[DataProvider('dialogTypes')]
  public function testCKEditor5FocusInTooltipsInDialog(string $link, bool $modal): void {
    FilterFormat::create([
      'format' => 'test_format',
      'name' => 'CKEditor 5 with link',
      'roles' => [RoleInterface::AUTHENTICATED_ID],
    ])->save();
    Editor::create([
      'format' => 'test_format',
      'editor' => 'ckeditor5',
      'image_upload' => [
        'status' => FALSE,
      ],
      'settings' => [
        'toolbar' => [
          'items' => ['link', 'heading'],
        ],
        'plugins' => [
          'ckeditor5_heading' => Heading::DEFAULT_CONFIGURATION,
        ],
      ],
    ])->save();

    $this->assertSame([], array_map(
      function (ConstraintViolationInterface $v): string {
        return (string) $v->getMessage();
      },
      iterator_to_array(CKEditor5::validatePair(
        Editor::load('test_format'),
        FilterFormat::load('test_format')
      ))
    ));

    $page = $this->getSession()->getPage();
    $assert_session = $this->assertSession();

    $this->drupalGet('/ckeditor5_test/dialog');
    $page->clickLink($link);
    $this->assertNotNull($assert_session->waitForElementVisible('css', 'dialog[open]'));
    $assert_session->assertWaitOnAjaxRequest();
    $this->assertSame($modal, $this->getSession()->evaluateScript('document.querySelector("dialog[open]").matches(":modal")'));

    $content_area = $assert_session->waitForElementVisible('css', '.ck-editor__editable');
    $this->chooseEditorHeading();
    // Focus the editable area first.
    $content_area->click();
    // Then press the button to add a link.
    $this->pressEditorButton('Link');
    $this->assertLinkBalloonPosition();
    $this->dismissEditorOverlay();
    $assert_session->assertNoElementAfterWait('css', '.ck-balloon-panel_visible input.ck-input-text');
    $assert_session->elementExists('css', 'dialog[open]');

    $this->pressEditorButton('Link');

    $link_url = '/ckeditor5_test/dialog';
    $input = $assert_session->waitForElementVisible('css', '.ck-balloon-panel input.ck-input-text');
    // Make sure the input field can have focus and we can type into it.
    $input->setValue($link_url);
    // Save the new link.
    $page->find('xpath', "//button[span[text()='Insert']]")->click();
    // Make sure something was added to the text.
    $this->assertNotEmpty($content_area->getText());
  }

  /**
   * Provides links for the modal and non-modal dialog cases.
   */
  public static function dialogTypes(): array {
    return [
      'modal' => ['Add Node', TRUE],
      'non-modal' => ['Add Node Without Modal', FALSE],
    ];
  }

  /**
   * Tests that openDialog() applies defaults only when settings are undefined.
   */
  public function testOpenDialogSettings(): void {
    FilterFormat::create([
      'format' => 'ckeditor5',
      'name' => 'CKEditor 5',
      'roles' => [RoleInterface::AUTHENTICATED_ID],
    ])->save();
    Editor::create([
      'format' => 'ckeditor5',
      'editor' => 'ckeditor5',
      'image_upload' => ['status' => FALSE],
      'settings' => [
        'toolbar' => ['items' => ['link']],
      ],
    ])->save();
    $this->assertSame([], array_map(
      function (ConstraintViolationInterface $v): string {
        return (string) $v->getMessage();
      },
      iterator_to_array(CKEditor5::validatePair(
        Editor::load('ckeditor5'),
        FilterFormat::load('ckeditor5')
      ))
    ));

    $this->drupalGet('/node/add/page');
    $this->waitForEditor();

    // When neither width nor autoResize is set, defaults should be applied.
    $result = $this->getSession()->evaluateScript(<<<JS
      (function() {
        let captured = null;
        const originalAjax = Drupal.ajax;
        Drupal.ajax = function(settings) { captured = settings; return { commands: {}, execute: function() {} }; };
        Drupal.ckeditor5.openDialog('/test', function() {}, {});
        Drupal.ajax = originalAjax;
        return { width: captured.dialog.width, autoResizeType: typeof captured.dialog.autoResize };
      })()
    JS);
    $this->assertSame('auto', $result['width']);
    $this->assertSame('boolean', $result['autoResizeType']);

    // A pre-defined width must not be overwritten by the 'auto' default.
    $result = $this->getSession()->evaluateScript(<<<JS
      (function() {
        let captured = null;
        const originalAjax = Drupal.ajax;
        Drupal.ajax = function(settings) { captured = settings; return { commands: {}, execute: function() {} }; };
        Drupal.ckeditor5.openDialog('/test', function() {}, { width: '500px' });
        Drupal.ajax = originalAjax;
        return captured.dialog.width;
      })()
    JS);
    $this->assertSame('500px', $result);

    // A pre-defined autoResize: false must not be overwritten.
    $result = $this->getSession()->evaluateScript(<<<JS
      (function() {
        let captured = null;
        const originalAjax = Drupal.ajax;
        Drupal.ajax = function(settings) { captured = settings; return { commands: {}, execute: function() {} }; };
        Drupal.ckeditor5.openDialog('/test', function() {}, { autoResize: false });
        Drupal.ajax = originalAjax;
        return captured.dialog.autoResize;
      })()
    JS);
    $this->assertFalse($result);

    // A pre-defined autoResize: true must not be overwritten.
    $result = $this->getSession()->evaluateScript(<<<JS
      (function() {
        let captured = null;
        const originalAjax = Drupal.ajax;
        Drupal.ajax = function(settings) { captured = settings; return { commands: {}, execute: function() {} }; };
        Drupal.ckeditor5.openDialog('/test', function() {}, { autoResize: true });
        Drupal.ajax = originalAjax;
        return captured.dialog.autoResize;
      })()
    JS);
    $this->assertTrue($result);

    // The drupal-dialog--narrow class must always be appended, even alongside
    // custom existing classes.
    $result = $this->getSession()->evaluateScript(<<<JS
      (function() {
        let captured = null;
        const originalAjax = Drupal.ajax;
        Drupal.ajax = function(settings) { captured = settings; return { commands: {}, execute: function() {} }; };
        Drupal.ckeditor5.openDialog('/test', function() {}, { classes: { 'drupal-dialog': 'my-custom-class' } });
        Drupal.ajax = originalAjax;
        return captured.dialog.classes['drupal-dialog'];
      })()
    JS);
    $this->assertStringContainsString('drupal-dialog--narrow', $result);
    $this->assertStringContainsString('my-custom-class', $result);
  }

}
